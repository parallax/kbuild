<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\OutputInterface;
use Miloske85\php_cli_table\Table as CliTable;
use DateTime;
use Exception;
use Symfony\Component\Yaml\Yaml;
use Aws\Iam\IamClient;  
use Aws\Exception\AwsException;

class CreateIam extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'create:iam 
        {--iam-account= : The iam user to create/ensure exists}
        {--settings= : The settings.yaml file to use}
        {--namespace= : The Kubernetes namespace to use for secrets}
        {--kubeconfig= : The path to the kubeconfig file}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Ensure a given IAM account exists';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        // Our function
        // Load in settings
        $this->settings = Yaml::parseFile($this->option('settings'));
        $kubeconfig = $this->option('kubeconfig');
        $kubernetesNamespace = $this->option('namespace');

        $iamClient = new IamClient([
            'region' => $this->settings['aws']['region'],
            'version' => '2010-05-08',
            'credentials' => [
                'key'    => $this->settings['aws']['awsAccessKeyId'],
                'secret' => $this->settings['aws']['awsSecretAccessKey'],
            ],
        ]);

        // Check if the IAM account already exists in our account
        try {
            $result = $iamClient->getUser([
                'UserName' => $this->option('iam-account'),
            ]);
            $accountExists = true;
            $this->info('IAM Account ' . $this->option('iam-account') . ' exists in account');
        } catch (AwsException $e) {
            $accountExists = false;
            $this->info('IAM Account ' . $this->option('iam-account') . ' does not exist, creating it');
        }

        // Check if account exists
        if ($accountExists === false) {
            try {
                $result = $iamClient->createUser([
                    'UserName' => $this->option('iam-account')
                ]);
            } catch (AwsException $e) {
                // output error message if fails
                $this->error('IAM Account ' . $this->option('iam-account') . ' could not be created');
                echo $e->getMessage();
                echo "\n";
                exit(1);
            }

            $this->info('Created IAM Account ' . $this->option('iam-account'));
        }

        // Get access key for user from Kubernetes
        $kubernetesAccessKey = json_decode(`kubectl --kubeconfig=$kubeconfig -n $kubernetesNamespace get secret aws-credentials -o json`, TRUE);

        if ($kubernetesAccessKey === null) {
            // Secret doesn't exist in Kubernetes
            $this->info('No aws-credentials secret in namespace ' . $this->option('namespace'));
            $result = $iamClient->createAccessKey([
                'UserName' => $this->option('iam-account'),
            ]);

            $this->info('Created access key ' . $result['AccessKey']['AccessKeyId']);

            // Apply the secret:
            $yamlArray = array(
                'apiVersion'    => 'v1',
                'kind'          => 'Secret',
                'metadata'      => array(
                    'name'          => 'aws-credentials',
                    'namespace'     => $this->option('namespace'),
                ),
                'type'          => 'Opaque',
                'stringData'    => array(
                    'AccessKeyId'      => $result['AccessKey']['AccessKeyId'],
                    'SecretAccessKey'  => $result['AccessKey']['SecretAccessKey']
                )
            );
    
            $command = "cat <<EOF | kubectl --kubeconfig=" . $this->option('kubeconfig') . " apply -f -\n" . Yaml::dump($yamlArray) . "\nEOF";
    
            system($command, $exit);
            if ($exit !== 0) {
                echo "Error creating aws-credentials secret\n";
                exit(1);
            }

            $this->info('Created secret aws-credentials with AccessKeyId ' . $result['AccessKey']['AccessKeyId']);
        }

        else {
            $this->info('Secret ' . $this->option('namespace') . '/aws-credentials exists with AccessKeyId ' . base64_decode($kubernetesAccessKey['data']['AccessKeyId']));
        }
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


?>