<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Spatie\Async\Pool as Pool;
use Spatie\Async\PoolStatus as PoolStatus;
use Spatie\Async\Task as Task;
use Spatie\Async\Process as Process;
use KubernetesRuntime\Client as KubernetesClient;
use Kubernetes\API\ConfigMap as ConfigMapAPI;
use Kubernetes\API\KubernetesNamespace as NamespaceAPI;
use Kubernetes\Model\Io\K8s\Api\Core\V1\ConfigMap;
use Kubernetes\Model\Io\K8s\Api\Core\V1\KubernetesNamespace;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Miloske85\php_cli_table\Table as CliTable;

/*
php kbuild build \
'https://api.prlx.kontainer.cloud' \
'/Users/lawrencedudley/Code/kbuild/certs/client-certificate-data' \
'/Users/lawrencedudley/Code/kbuild/certs/client-key-data' \
'/Users/lawrencedudley/Code/kbuild/certs/certificate-authority-data' \
'hiya' \
'master' \
'qa' \
'1'
*/

class CustomPool extends Pool
{
    public function waitWithStatus($poolStatus, ?callable $intermediateCallback = null): array
    {
        while ($this->inProgress) {
            foreach ($this->inProgress as $process) {
                if ($process->getCurrentExecutionTime() > $this->timeout) {
                    $this->markAsTimedOut($process);
                }
                if ($process instanceof SynchronousProcess) {
                    $this->markAsFinished($process);
                }
            }
            if (! $this->inProgress) {
                break;
            }
            if ($intermediateCallback) {
                call_user_func_array($intermediateCallback, [$this]);
            }
            usleep($this->sleepTime);

            print_r($poolStatus->table());
        }

        return $this->results;
    }
}

class DeclareNamespace extends Task
{

    protected $args;
    protected $master;
    protected $authentication;
    protected $certificateAuthorityData;

    public function __construct($args) {
        $this->namespace = $args['namespace'];
        $this->master = $args['master'];
        $this->authentication = $args['authentication'];
        $this->certificateAuthorityData = $args['certificateAuthorityData'];
    }

    public function configure()
    {
        // Initialise the Kubernetes Client
        KubernetesClient::configure($this->master, $this->authentication, [
            'verify' => $this->certificateAuthorityData
        ]);
    }

    public function run()
    {
        // Do the real work here.
        // Initialise the API
        $namespaceAPI = new NamespaceAPI();

        if ($namespaceAPI->read($this->namespace)->status === 'Failure') {
            $kubernetesNamespace = new KubernetesNamespace([
                'metadata' => [
                    'name' => $this->namespace
                ],
            ]);
            $namespaceAPI->create($kubernetesNamespace);
        }

        // Check if the namespace exists
        $response = $namespaceAPI->read($this->namespace);

        return $response;
    }
}

class PoolStatusTable
{

    protected $pool;
    protected $jobs;

    public function __construct($args) {
        $this->pool = $args['pool'];
        $this->jobs = array();
    }

    public function headers() {
        $headers = array(
            'Job',
            'Time',
            'Status'
        );
        return $headers;
    }

    public function table() {
        // Pool status
        print_r($this->pool);
        $statusRows = array();
        $finishedJobs = $this->pool->getFinished();
        foreach ($this->jobs as $pid => $status) {
            $jobName = $this->jobs[$pid];
            array_push($statusRows, array(
                $jobName,
                '1',
                //round($this->pool->getCurrentExecutionTime($pid), 2),
                'Done'
            ));
        }    
        return $statusRows;
    }

    public function addJob($job, $pid) {
        $this->jobs[$pid] = $job;
    }

    public function jobs() {
        return $this->jobs;
    }

        
}


class Build extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'build 
        {master : The url for the kubernetes api}
        {client-certificate-data : Client certificate}
        {client-key-data : Client key}
        {certificate-authority-data : Certificate authority data}
        {app : The name of the app}
        {branch : The name of the app branch}
        {environment : The name of the environment}
        {build : The number of the build}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Build a kbuild project';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        // Initialise variables to pass to Kubernetes client - https://github.com/allansun/kubernetes-php-client
        $master = $this->argument('master');
        $authentication = [
            'clientCert' => $this->argument('client-certificate-data'),
            'clientKey' => $this->argument('client-key-data')
        ];

        // Create the worker pool
        $pool = CustomPool::create()
            ->autoload(__DIR__ . '/../../vendor/autoload.php');

        // Check for async support
        if ($pool->isSupported() === TRUE) {
            $this->info('Asynchronous execution is supported on this platform');
        }
        else {
            $this->error('Asynchronous execution is not supported on this platform. Builds will run more slowly.');
        }
        
        // Output what we're building
        $this->table(
            // Headers
            [
                'App',
                'Branch',
                'Build',
                'Environment',
            ],
            // Data
            [
                [
                    $this->argument('app'),
                    $this->argument('branch'),
                    $this->argument('build'),
                    $this->argument('environment'),  
                ]
            ]
        );

        // Initialise our custom pool status table outputter
        $poolStatus = new PoolStatusTable(
            array(
                'pool' => $pool,
            )
        );

        // Job handling
        // Ensure namespace exists
        $jobName = 'Declare Namespace';
        $pid = ($pool->add(new DeclareNamespace(
            array(
                'namespace' => $this->argument('app') . '-' . $this->argument('environment'),
                'master' => $master,
                'authentication' => $authentication,
                'certificateAuthorityData' => $this->argument('certificate-authority-data'),
            )
        ))->getPid());
        // Add the pid to the array so we can use pretty names
        $poolStatus->addJob($jobName, $pid);

        // Ensure namespace exists
        $jobName = 'Declare Namespace';
        $pid = ($pool->add(new DeclareNamespace(
            array(
                'namespace' => $this->argument('app') . '-' . $this->argument('environment'),
                'master' => $master,
                'authentication' => $authentication,
                'certificateAuthorityData' => $this->argument('certificate-authority-data'),
            )
        ))->getPid());
        // Add the pid to the array so we can use pretty names
        $poolStatus->addJob($jobName, $pid);


        // Retrieve status table
        $table = new CliTable($poolStatus->table(), $poolStatus->headers());
        echo $table->getTable();

        $pool->wait();

    }

    /**
     * Define the command's schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    public function schedule(Schedule $schedule)
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
