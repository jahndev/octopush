<?php

namespace Controllers;

use Models\JobStatus,
    Models\JobMapper,
    Models\Job,
    Models\Version,
    Services\ThirdParty,
    Library\OctopushApplication,
    Helpers\Session,
    Symfony\Component\HttpFoundation\Request,
    Symfony\Component\HttpFoundation\Response;

/* Handle requests related to specific jobs, all request expects a job_id parameter */
class JobsController
{
    private $_config;
    private $_jobMapper;
    private $_log;
    private $_app;
    private $_thirdParty;
    private $_url_prefix;
    private $_regex_version;
    private $_uri_version;

    public function __construct(OctopushApplication $app,
                                $config,
                                JobMapper $jobMapper,
                                $jenkins,
                                $url_prefix,
                                $uri_version,
                                $regex_version,
                                $log)
    {
        $this->_config = $config;
        $this->_jobMapper = $jobMapper;
        $this->_log = $log;
        $this->_app = $app;
        $this->_jenkins = $jenkins;
        $this->_thirdParty = $app['services.ThirdParty'];
        $this->_url_prefix = $url_prefix;
        $this->_uri_version = $uri_version;
        $this->_regex_version = $regex_version;

    }

    public function createJob(Request $request)
    {
        $config = $this->_config;
        $jenkins = '';

        $env = 'testing';
        $jenkins = $request->get('requestor');
        $module = $request->get('module');
        $version = $request->get('version');

        if (!array_key_exists($module, $config['modules'])) {
            $error = array(
                'status' => "error",
                'message' => $module . " is not a valid module to push."
            );
            $this->_log->addError($error['message']);

            return $this->_app->json($error);
        }

        try {
            $job = Job::createWith($module, $version, $env, $jenkins);
            $this->_jobMapper->save($job);

            $result = array(
                'status' => "success",
                'message' => "Job inserted in queue",
                'job_id' => (int) $job->getId(),
            );
            $this->_log->addInfo($result['message'] . " with id: " . $result['job_id']);
        } catch (\Exception $exc) {
            $result = array(
                'status' => "error",
                'message' => "Job not inserted in queue",
                'detail' => $exc->getMessage(),
            );
            $this->_log->addError($result['message'] . " :: " . $result['detail']);
        }

        return $this->_app->json($result);
    }

    public function getJobStatus($jobId)
    {
        try {
            $job = $this->_jobMapper->get($jobId);
            $result = array(
                'job_status' => $job->getStatus(),
                'job_id' => $jobId
            );

            return $this->_app->json($result);
        } catch (\Exception $exc) {
            $error = array(
                'status' => "error",
                'message' => "Problems trying to get job status",
                'detail' => $exc->getMessage(),
            );
            $this->_log->addError($error['message'] . " :: " . $error['detail']);

            return $this->_app->json($error);
        }
    }

    public function cancel($jobId)
    {
        try {
            $job = $this->_jobMapper->get($jobId);

            $status = array(JobStatus::QUEUED => JobStatus::DEPLOY_FAILED,
                            JobStatus::QUEUED_FOR_LIVE => JobStatus::GO_LIVE_FAILED);

            if (!isset($status[$job->getStatus()]))
                throw new \Exception('Unable to cancel a job on status: ' . $job->getStatus());

            if (($job->getStatus() == JobStatus::QUEUED_FOR_LIVE) && (!$this->canBePushedLive($job)))
                throw new \Exception('No permissions to cancel: ' . $jobId);

            if ($job->getStatus() == JobStatus::QUEUED_FOR_LIVE)
                $response = $this->_thirdParty->postDeploy($job, ThirdParty::DEPLOY_CANCEL);

            $job->moveStatusTo($status[$job->getStatus()]);
            $this->_jobMapper->save($job);

            $result = array(
                'job_status' => $job->getStatus(),
                'job_id' => $jobId
            );

            return $this->_app->json($result);

        } catch (\Exception $exc) {
            $error = array(
                'status' => "error",
                'message' => "Problems trying to cancel job",
                'detail' => $exc->getMessage(),
            );
            $this->_log->addError($error['message'] . " :: " . $error['detail']);

            return $this->_app->json($error);
        }
    }

    private function getKeyAndSession(){
        if (isset($_REQUEST['access_token'])){
            Session::buildBackendSession($this->_app, $_REQUEST['access_token']);
        }
    }

    public function goLive($jobId)
    {
        try {
            $job = $this->_jobMapper->get($jobId);
            $this->getKeyAndSession();

            $helperSession = $this->_app['helpers.session'];

            if ($this->canBePushedLive($job) &&
                      $job->canGoLive()) {

                $email = $helperSession->getUser()->getEmail();
                if (!empty($email))
                    $job->setUser($email);
                else
                    $job->setUser($helperSession->getUser()->getUserName());

                $ticket = $this->_thirdParty->preDeploy($job);

                if (isset($ticket)) {
                    $job->setTicket($ticket);
                    $job->moveStatusTo(JobStatus::QUEUED_FOR_LIVE);
                } else {
                    $job->movestatusTo(JobStatus::GO_LIVE_FAILED);
                }

                $this->_jobMapper->save($job);

                $result = array(
                    'job_status' => $job->getStatus(),
                    'job_id' => $jobId
                );
                return $this->_app->json($result);
            } else {
                $result = array(
                'job_status' => $job->getStatus(),
                'job_id' => $jobId,
                'status' => "Error",
                'message' => "The job is not in a valid status to go live or "
                    . "you don't have permissions to do this action"
                );
                return $this->_app->json($result);
            }
        } catch (\Exception $exc) {
            $error = array(
                'status' => "error",
                'message' => "Problems trying to go live with Job",
                'detail' => $exc->getMessage(),
            );
            $this->_log->addError($error['message'] . " :: " . $error['detail']);

            return $this->_app->json($error);
        }
    }

    public function rollback($jobId)
    {
        try {
            $oldJob = $this->_jobMapper->get($jobId);
            $this->getKeyAndSession();

            $helperSession = $this->_app['helpers.session'];

            if (! $this->canBePushedLive($oldJob) ||
                ! $oldJob->wentLive()) {

                $result = array(
                    'job_status' => $oldJob->getStatus(),
                    'job_id' => $jobId,
                    'status' => "Error",
                    'message' => "The job is not in a valid status to rollback "
                        . "or you don't have permissions to do this action"
                );

                return $this->_app->json($result);
            }

            $job = Job::createWith($oldJob->getTargetModule(), $oldJob->getTargetVersion(),
                    $oldJob->getTargetEnvironment(), $oldJob->getRequestorJenkins());
            $job->movestatusTo(JobStatus::QUEUED_FOR_LIVE);
            $job->setRollbackedFrom($oldJob->getId());
            $job->setTicket($oldJob->getTicket());

            $ticket = $this->_thirdParty->preDeploy($job, ThirdParty::ROLLBACK_ACTION);
            $job->setTicket($ticket);
            $job->setUser($helperSession->getUser()->getEmail());

            $this->_jobMapper->save($job);

            $result = array(
                'status' => "success",
                'message' => "Job inserted in queue",
                'job_id' => (int) $job->getId(),
            );
            $this->_log->addInfo($result['message'] . " with id: " . $result['job_id']);

            return $this->_app->json($result);
        } catch (\Exception $exc) {
            $result = array(
                'status' => "error",
                'message' => "Job not inserted in queue",
                'detail' => $exc->getMessage(),
            );
            $this->_log->addError($result['message'] . " :: " . $result['detail']);
            return $this->_app->json($result);
        }

    }

    public function registerTestJobUrl(Request $request)
    {
        try {
            $jobId = $request->get('jobId');
            $url = $request->get('test_job_url');
            $job = $this->_jobMapper->get($jobId);
            $job->setTestJobUrl($url);

            $this->_jobMapper->save($job);

            $result = array(
                'status' => "success",
                'message' => "Test job url registerd"
            );

            return $this->_app->json($result);
        } catch (\Exception $exc) {
            $error = array(
                'status' => "error",
                'message' => "Problems trying to register Test job url",
                'detail' => $exc->getMessage(),
            );
            $this->_log->addError($error['message'] . " :: " . $error['detail']);

            return $this->_app->json($error);
        }
    }

    public function registerTestJobResult(Request $request)
    {
        try {
            $jobId = $request->get('jobId');
            $success = $request->get('success');
            $status = $success == 'true' ? JobStatus::TESTS_PASSED : JobStatus::TESTS_FAILED;

            $job = $this->_jobMapper->get($jobId);
            $job->moveStatusTo($status);

            $this->_jobMapper->save($job);

            $result = array(
                'status' => "success",
                'message' => "Test result registered"
            );

            return $this->_app->json($result);
        } catch (\Exception $exc) {
            $error = array(
                'status' => "error",
                'message' => "Problems trying to register Test result",
                'detail' => $exc->getMessage(),
            );
            $this->_log->addError($error['message'] . " :: " . $error['detail']);

            return $this->_app->json($error);
        }
    }

    public function registerTestResult($jobId, $success)
    {
        try {
            $status = $success == 'true' ? JobStatus::TESTS_PASSED : JobStatus::TESTS_FAILED;

            $job = $this->_jobMapper->get($jobId);
            $job->moveStatusTo($status);

            $this->_jobMapper->save($job);

            $result = array(
                'status' => "success",
                'message' => "Test result registered"
            );

            return $this->_app->json($result);
        } catch (\Exception $exc) {
            $error = array(
                'status' => "error",
                'message' => "Problems trying to register Test result",
                'detail' => $exc->getMessage(),
            );
            $this->_log->addError($error['message'] . " :: " . $error['detail']);

            return $this->_app->json($error);
        }
    }

    public function canBePushedLive($job)
    {

        $permissions = $this->_app['helpers.session']->getPermissions();

        if (isset($permissions)) {
            if ($this->_thirdParty->canMemberGoLive(
                $permissions,
                $job->getTargetModule()
            )) {
                return true;
            }
        }

        return false;
    }



    /*************************/

    public function deploying() //DEPRECATE and use inprogress(live)
    {
        $jobsGoingLive = $this->_jobMapper->findAllByStatus(JobStatus::GOING_LIVE);
        if (count($jobsGoingLive) == 0) {
            $result = array(
                'status' => "Idle",
                'module' => "-",
                'version' => "-",
            );
            return $this->_app->json($result);
        }
        foreach ($jobsGoingLive as $job) {
            $result = array(
                'status' => "Deploying",
                'module' => $job->getTargetModule(),
                'version' => $job->getTargetVersion(),
            );
            return $this->_app->json($result);

        }
    }

    public function my_components($state)
    {
        $sessionHelper = $this->_app['helpers.session'];

        $sessionHelper->setMyComponents($state);

        return $sessionHelper->isMyComponentsOn();
    }

    public function all(){
        $queueLenght = $this->getPageSize();
        $repos = $this->getRepoFilter();

        $all = array('preprodQueue' => $this->_queued(Version::STAGING));
        $all['preprodInprogress'] = $this->_inprogress(Version::STAGING);
        $all['preprodDeployed'] = $this->_deployed(Version::STAGING, $repos, $queueLenght);
        $all['prodQueue'] = $this->_queued(Version::PRODUCTION);
        $all['prodInprogress'] = $this->_inprogress(Version::PRODUCTION);
        $all['prodDeployed'] = $this->_deployed(Version::PRODUCTION, $repos, $queueLenght);

        return $this->_app->json($all);
    }

    private function _deployed($env, $repos, $queueLenght = 10)
    {
        $statuses = array(Version::STAGING => array(JobStatus::TESTS_PASSED, JobStatus::TESTS_FAILED, JobStatus::DEPLOY_FAILED),
            Version::PRODUCTION => array(JobStatus::GO_LIVE_DONE, JobStatus::GO_LIVE_FAILED));

        $result =  $this->_jobMapper->findAllByMultipleStatusAndModules($statuses[$env], $repos, $queueLenght);

        $result = $this->fillResults($result, $this->_jenkins);
        return $result;
    }

    private function fillResults($data, $jenkins){
        $result = array();
        foreach ($data as $record) {
            $job = Job::createFromArray($record);
            $job_array = $job->serialize();

            $job_array['_buildJobUrl'] = $jenkins->getRequestorJobConsoleUrl($job);
            $job_array['_deployJobUrl'] = $job->getDeploymentJobId();#$jenkins->getPreProdJobDeployUrl($job);
            $job_array['_deployLiveJobUrl'] = $job->getLiveJobId();#$jenkins->getLiveJobDeployUrl($job);

            $canBePushedLive = $this->canBePushedLive($job);
            $job_array['_canGoLive'] = ($job->canGoLive() && $canBePushedLive);

            $job_array['_canRollback'] = ($job->wentLive() && $canBePushedLive);

            $job_array['_canCancel'] = ($canBePushedLive);

            $job_array['_serverTime'] = date('Y-m-d') . 'T' . date('H:i:s') . 'Z';
            $queueDate = new \DateTime($job->getQueuedDate());
            $updatedDate = new \DateTime($job->getUpdateDate());
            $job_array['_queued_at'] = $queueDate->format('Y-m-d') .
                              'T' . $queueDate->format('H:i:s') . 'Z';
            $job_array['_updated_at'] = $updatedDate->format('Y-m-d') .
                              'T' . $updatedDate->format('H:i:s') . 'Z';

            preg_match($this->_regex_version, $job_array['_targetVersion'], $match);
            if (! empty ( $match ))
              $job_array['_version_link'] = $this->_url_prefix . $job_array['_targetModule']
                    . $this->_uri_version . $match[0];
            else
              $job_array['_version_link'] = $this->_url_prefix . $job_array['_targetModule'];

            array_push($result, $job_array);
        }

        return $result;
    }


    public function deployed($env)
    {
        $queueLenght = $this->getPageSize();
        $repos = $this->getRepoFilter();

        $deployed = $this->_deployed($env, $repos, $queueLenght);
        return $this->_app->json($deployed);
    }

   public function queued($env)
    {
        $queued = $this->_queued($env);
        return $this->_app->json($queued);
    }

   private function _queued($env)
    {
        $statuses = array(Version::STAGING => array(JobStatus::QUEUED),
            Version::PRODUCTION => array(JobStatus::QUEUED_FOR_LIVE));

        $queuedJobs = $this->_jobMapper->findAllByMultipleStatusAndModules($statuses[$env], array());

        $result = $this->fillResults($queuedJobs, $this->_jenkins);
        return $result;
    }

   public function inprogress($env)
    {
        $inprogress = $this->_inprogress($env);
        return $this->_app->json($inprogress);
    }

   private function _inprogress($env)
    {
        $statuses = array(Version::STAGING => array(JobStatus::DEPLOYING, JobStatus::PENDING_TESTS),
            Version::PRODUCTION => array(JobStatus::GOING_LIVE));

        $inProgressJobs = $this->_jobMapper->findAllByMultipleStatusAndModules($statuses[$env], array());

        $result = $this->fillResults($inProgressJobs, $this->_jenkins);
        return $result;
    }


    private function getPageSize()
    {
        if (isset($_REQUEST['pageSize']))
            $queueLenght = $_REQUEST['pageSize'];
        else
            $queueLenght = $this->_config['jobs']['queue.lenght'] ?
                $this->_config['jobs']['queue.lenght'] : null;

        return $queueLenght;
    }

    private function getRepoFilter()
    {
        $sessionHelper = $this->_app['helpers.session'];

        if (isset($_REQUEST['repo']))
        {
            $repo = array($_REQUEST['repo']);
        }
        else
            $repo = array();

        return $repo;
    }
}
