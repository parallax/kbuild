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

class DockerFiles {

    protected $directory;
    protected $buildDirectory;
    protected $asArray;
    protected $app;
    protected $branch;
    protected $build;
    protected $taskSpooler;
    protected $cloudProvider;
    protected $kbuild;
    public $imageTags;

    public function __construct($args) {
        $this->buildDirectory = $args['buildDirectory'];
        if (count($this->asArray()) === 0) {
            $this->noFiles();
        }
        $this->app = $args['app'];
        $this->branch = $args['branch'];
        $this->build = $args['build'];
        $this->taskSpooler = $args['taskSpooler'];
        $this->cloudProvider = $args['cloudProvider'];
        $this->settings = $args['settings'];
        $this->imageTags = array();
    }

    public function noFiles() {
        echo "No Dockerfiles have been found - this might be an error or in some edge cases it might be fine. Beware!\n";
    }

    public function build($dockerFile) {
        exec('docker build -t test -f ' . $this->buildDirectory . '/k8s/docker/' . $dockerFile . ' ' . $this->buildDirectory);
    }

    public function asArray() {
        // Find all docker files in k8s/docker that we need to build
        $dockerFiles = scandir($this->buildDirectory . '/k8s/docker/');

        // Filter for anything beginning with a .
        foreach ($dockerFiles as $key => $dockerFile) {
            if(strpos($dockerFile, '.') === 0) {
                unset($dockerFiles[$key]);
            }
        }

        return $dockerFiles;
    }

    public function getImageTags() {
        return $this->imageTags;
    }

    public function asTableArray() {

        $response = array();

        if (count($this->asArray()) !== 0) {
            foreach ($this->asArray() as $key => $value) {
                array_push($response, array($value));
            }
            return $response;
        }
        
        else {
            return array();
        }
        

    }

    public function repositoryBase() {
        switch ($this->cloudProvider) {
            case 'aws':

                $awsAccessKeyId = $this->settings['aws']['awsAccessKeyId'];
                $awsSecretAccessKey = $this->settings['aws']['awsSecretAccessKey'];
                $awsRegion = $this->settings['aws']['region'];

                $dockerLogin = `export AWS_ACCESS_KEY_ID=$awsAccessKeyId && export AWS_SECRET_ACCESS_KEY=$awsSecretAccessKey && aws ecr get-login --region=$awsRegion --no-include-email`;
                preg_match('/(https:\/\/.*)/', $dockerLogin, $repositoryBase);
                $repositoryBase = str_replace('https://', '', $repositoryBase[1]);

                break;
            
            case 'gcp':
                # code...
                break;
        }

        return $repositoryBase;
    }

    public function asTable() {
        
        if (count($this->asArray()) !== 0) {
            $headers = array(
                'Dockerfile',
            );
            $table = new CliTable($this->asTableArray(), $headers);
            echo $table->getTable();
            echo "\n";
        }
    }

    public function buildAndPush($actuallyBuild = true) {

        $files = $this->asArray();
        $buildDirectory = $this->buildDirectory;
        $app = $this->app;
        $branch = $this->branch;
        $build = $this->build;
        $taskSpooler = $this->taskSpooler;
        $provider = $this->cloudProvider;

        // For each docker file, get it queued up as a job
        foreach ($files as $key => $dockerFile) {

            switch ($provider) {
                case 'aws':
    
                    $awsAccessKeyId = $this->settings['aws']['awsAccessKeyId'];
                    $awsSecretAccessKey = $this->settings['aws']['awsSecretAccessKey'];
                    $awsRegion = $this->settings['aws']['region'];

                    $dockerLogin = `export AWS_ACCESS_KEY_ID=$awsAccessKeyId && export AWS_SECRET_ACCESS_KEY=$awsSecretAccessKey && aws ecr get-login --region=$awsRegion --no-include-email`;
                    preg_match('/-p (.*\ )/', $dockerLogin, $dockerPassword);
                    $dockerPassword = $dockerPassword[1];
                    preg_match('/(https:\/\/.*)/', $dockerLogin, $repositoryBase);
                    $repositoryBase = $repositoryBase[1];
    
                    // Run ECR login
                    $ecrLogin = `echo $dockerPassword | docker login -u AWS --password-stdin $repositoryBase`;
    
                    // Ensure that the ECR repository exists for this app
                    // Describe the repositories on the account
                    $repositories = json_decode(`export AWS_ACCESS_KEY_ID=$awsAccessKeyId && export AWS_SECRET_ACCESS_KEY=$awsSecretAccessKey && aws ecr describe-repositories --region=$awsRegion`);
                    if ($repositories === NULL) {
                        echo "💥💥💥 Error getting ECR repositories. This could be an AWS or an IAM issue. 💥💥💥\n";
                        exit(1);
                    }
                    
                    // See if any of the names match
                    $repositoryExists = FALSE;
                    foreach ($repositories->repositories as $key => $repository) {
                        if ($repository->repositoryName === $app) {
                            $repositoryExists = TRUE;
                        }
                    }
    
                    // Doesn't exist, create it
                    if ($repositoryExists === FALSE) {
                        $createRepository = `export AWS_ACCESS_KEY_ID=$awsAccessKeyId && export AWS_SECRET_ACCESS_KEY=$awsSecretAccessKey && aws ecr create-repository --repository-name $app`;
                    }
    
                    $repositoryBase = str_replace('https://', '', $repositoryBase);
    
                    $tag = $repositoryBase . '/' . $app . ':' . $dockerFile . '-' . $branch . '-' . $build;
                    $this->imageTags[$dockerFile] = $tag;
                    if ($actuallyBuild === true) {
                        $taskSpooler->addJob('Dockerfile ' . $dockerFile, 'docker build -t ' . $tag . ' -f ' . $buildDirectory . '/k8s/docker/' . $dockerFile . ' . && docker push ' . $tag . ' && echo "Pushed to ' . $tag . '"');
                    }

                    break;
                
                case 'gcp':
                    # code...
                    break;
            }
        }

        return $repositoryBase;
    }

}