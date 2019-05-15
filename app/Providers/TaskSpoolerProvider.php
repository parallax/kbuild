<?php

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Miloske85\php_cli_table\Table as CliTable;
use DateTime;
use Exception;
use Symfony\Component\Yaml\Yaml;
use Illuminate\Support\ServiceProvider;

class TaskSpoolerInstance
{

    protected   $instance;
    protected   $jobs;
    protected   $dependencies;
    private     $tableData;
    private     $repositoryBase;

    /* Usage:
    Initialise your taskSpooler instance
    $taskSpooler = new TaskSpoolerInstance();


    // Add jobs to your taskspool. Returns the id of the job:
    $jobId = $taskSpooler->addJob('Step One', 'docker build --no-cache -t test -f k8s/docker/nginx-php .');


    // Add a job to your taskspool that should only run when the id of another job has completed:
    $taskSpooler->addJob('Step Five', 'docker build --no-cache -t test -f k8s/docker/nginx-php .', $jobId);

    // Wait for the queue to complete:
    $taskSpooler->wait();

    // Will output pretty table as follows:
    +------------------+------------------+------------------+---------------------------------+
    | Job              | Duration         | Exit Code        | Last Output Line                |
    +------------------+------------------+------------------+---------------------------------+
    | Step One         | 0m 7s            | 0                | Successfully tagged test:latest |
    | -> Step Two      | 0m 7s            | 0                | Successfully tagged test:latest |
    | --> Step Three   | 0m 6s            | 0                | Successfully tagged test:latest |
    | ---> Step Four   | 0m 5s            | -                | ---> Running in 1453e97f6d0a    |
    | -> Step Five     | 0m 7s            | 0                | Successfully tagged test:latest |
    | Step Six         | 0m 7s            | 0                | Successfully tagged test:latest |
    +------------------+------------------+------------------+---------------------------------+

    The jobs are listed in order of dependencies with the -> bits signifying how deep down the dependency tree each function is

    */

    public function __construct() {
        $this->instance = '/tmp/' . hash('md5', microtime() . rand());
        $this->export = 'export TS_SLOTS=100; export TS_SOCKET=' . $this->instance . '; ';
        $this->jobs = array ();
        $this->dependencies = array ();
        $this->repositoryBase = '';
    }

    public function kill() {
        shell_exec($this->export . 'tsp -K');
    }

    public function addJob($name, $command, $dependency = NULL) {

        if ($dependency !== NULL) {
            $dependencyCommand = '-D ' . $dependency . ' ';
        }

        else {
            $dependencyCommand = '';
        }
        $jobId = trim(shell_exec($this->export . 'tsp ' . "-L '$name' $dependencyCommand" .  "bash -c '$command'"));
        $info = trim(shell_exec($this->export . 'tsp -i ' . $jobId));

        $this->dependencies[$jobId]['name'] = $name;
        $this->dependencies[$jobId]['jobId'] = $jobId;

        // Add to the dependencies array that we'll use later to figure out what jobs depend on each other
        if ($dependency !== NULL) {
            // This job has a dependency. Add it to the dependencies array and figure out which level it sits at.
            // First check the job this depends on exists, error if not
            if (!isset($this->dependencies[$dependency])) {
                echo "💥💥💥 Job \"$name\" depends on JobID $dependency which doesn't exist 💥💥💥\n";
                exit(1);
            }
            // If it does in fact exist...
            // Push the dependency to the children array of the job this job depends on
            array_push($this->dependencies[$dependency]['children'], $jobId);
            // Then create a top-level item with the level set to that of the job it is dependent on +1
            $this->dependencies[$jobId]['level'] = $this->dependencies[$dependency]['level'] + 1;
            if (!isset($this->dependencies[$jobId]['children'])) {
                $this->dependencies[$jobId]['children'] = array ();
            }
        }

        else {
            $this->dependencies[$jobId]['level'] = 0;
            if (!isset($this->dependencies[$jobId]['children'])) {
                $this->dependencies[$jobId]['children'] = array ();
            }
        }

        $this->jobs[$jobId] = array(
            'id' => $jobId,
            'name' => $name,
            'status' => trim(shell_exec($this->export . 'tsp -s ' . $jobId)),
            'exit' => $this->getExitCode($info),
            'command' => $this->getCommand($info),
            'dependency' => $dependency
        );

        if ($this->jobs[$jobId]['status'] !== 'queued' && $this->jobs[$jobId]['status'] !== 'skipped') {
            $this->jobs[$jobId]['output'] = trim(shell_exec($this->export . 'cat ' . trim(shell_exec($this->export . 'tsp -o ' . $jobId))));
            $this->jobs[$jobId]['lastLine'] = preg_replace('/^[ \t]*[\r\n]+/m', '', trim(shell_exec($this->export . 'cat ' . trim(shell_exec($this->export . 'tsp -o ' . $jobId)) . '| tail -n 1')), );
            $this->jobs[$jobId]['exit'] = $this->getExitCode($info);
            $this->jobs[$jobId]['duration'] = $this->getDuration($info);
        }
        else {
            $this->jobs[$jobId]['duration'] = 'Queued';
            $this->jobs[$jobId]['exit'] = '-';
            $this->jobs[$jobId]['lastLine'] = '';
            $this->jobs[$jobId]['output'] = '';
        }

        return $jobId;
    }

    public function getJobs() {
        foreach ($this->jobs as $jobId => $job) {
            $info = trim(shell_exec($this->export . 'tsp -i ' . $jobId));
            $this->jobs[$jobId]['status'] = trim(shell_exec($this->export . 'tsp -s ' . $jobId));
            $this->jobs[$jobId]['exit'] = $this->getExitCode($info);
            if ($this->jobs[$jobId]['status'] !== 'queued' && $this->jobs[$jobId]['status'] !== 'skipped') {

                $this->jobs[$jobId]['output'] = trim(shell_exec($this->export . 'cat ' . trim(shell_exec($this->export . 'tsp -o ' . $jobId))));
                $this->jobs[$jobId]['lastLine'] = trim(shell_exec($this->export . 'cat ' . trim(shell_exec($this->export . 'tsp -o ' . $jobId)) . '| tail -n 1'));
                $this->jobs[$jobId]['duration'] = $this->getDuration($info);
            }
            else {
                $this->jobs[$jobId]['duration'] = 'Queued';
            }
        }

        return $this->jobs;
    }

    public function getExitCode($info) {
        $lines = explode("\n", $info);
        foreach ($lines as $key => $line) {
            if(strpos($line, 'Exit status: died with exit code ') === 0) {
                $exit = str_replace('Exit status: died with exit code ', '', $line);
            }
            if(strpos($line, 'Exit status: killed by signal ') === 0) {
                $exit = str_replace('Exit status: killed by signal ', '', $line);
            }
        }
        if (!isset($exit)) {
            $exit = '-';
        }
        return $exit;
    }

    public function getCommand($info) {
        $lines = explode("\n", $info);
        foreach ($lines as $key => $line) {
            if(strpos($line, 'Command: ') === 0) {
                $command = str_replace('Command: ', '', $line);
            }
        }

        return $command;
    }

    public function getDuration($info) {
        $lines = explode("\n", $info);
        foreach ($lines as $key => $line) {
            if(strpos($line, 'Start time: ') === 0) {
                $startTime = new DateTime(str_replace('Start time: ', '', $line));
            }
            if(strpos($line, 'End time: ') === 0) {
                $endTime = new DateTime(str_replace('End time: ', '', $line));
            }
        }

        if(!isset($endTime)) {
            $endTime = new DateTime();
        }

        if(!isset($startTime)) {
            $startTime = new DateTime();
        }

        $interval = date_diff($startTime, $endTime);

        return $interval->format("%im %ss");
    }

    private function dependencyIterator($job) {


        // Set the name up with -- and > if it's a child
        if ($job['level'] === 0) {
            $name = $job['name'];
        }
        else {
            $name = str_repeat('-', $job['level']) . '> ' . $job['name'];
        }
        $jobId = $job['jobId'];

        array_push($this->tableData, array(
            'name' => $name,
            'duration' => $this->jobs[$jobId]['duration'],
            'exit' => $this->jobs[$jobId]['exit'],
            'lastLine' => $this->jobs[$jobId]['lastLine'],
        ));

        if (count($job['children']) > 0) {
            foreach ($job['children'] as $key => $child) {
                $this->dependencyIterator($this->dependencies[$child]);
            }
        }
    }

    public function wait() {
        $headers = array(
            'Job',
            'Duration',
            'Exit Code',
            'Last Output Line',
        );
        $done = false;

        while ($done !== true) {
            sleep(1);
            $tabulated = array();
            $this->tableData = array();
            $this->getJobs();
            $done = true;

            foreach ($this->dependencies as $jobId => $job) {
                // Filter for ones that are level 0:
                if ($job['level'] === 0) {
                    $this->dependencyIterator($job);
                }

                if ($this->jobs[$jobId]['exit'] === '-') {
                    $done = false;
                }
                // An error has occurred
                if ($this->jobs[$jobId]['exit'] !== '-' && $this->jobs[$jobId]['exit'] !== '0') {
                    echo "💥💥💥 Error running job \"" . $this->jobs[$jobId]['name'] . "\". Exit Code: " . $this->jobs[$jobId]['exit'] . " 💥💥💥\nLog:\n" . $this->jobs[$jobId]['output'];
                    $this->kill();
                    exit(1);
                }
            }

            $table = new CliTable($this->tableData, $headers);

            echo $table->getTable();

        }
    }

}
