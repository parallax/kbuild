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
use Aws\S3\S3Client;  
use Aws\Exception\AwsException;
use Aws\Iam\IamClient;  

class CreateS3Bucket extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'create:s3bucket 
        {--bucket-name= : The namespace to create/ensure exists}
        {--iam-grantee= : The IAM user to grant bucket access to}
        {--settings= : The settings.yaml file to use}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Ensure a given S3 bucket exists';

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

        $s3Client = new S3Client([
            'region' => $this->settings['aws']['region'],
            'version' => '2006-03-01',
            'credentials' => [
                'key'    => $this->settings['aws']['awsAccessKeyId'],
                'secret' => $this->settings['aws']['awsSecretAccessKey'],
            ],
        ]);

        // Check if the bucket already exists in our account
        $buckets = $s3Client->listBuckets();

        $bucketExists = false;

        foreach ($buckets['Buckets'] as $bucket) {
            if ($bucket['Name'] === $this->option('bucket-name')) {
                $this->info('Bucket ' . $this->option('bucket-name') . ' exists already');
                $bucketExists = true;
            }

        }

        // Check if bucket exists
        if ($bucketExists === false) {
            $this->info('Bucket ' . $this->option('bucket-name') . ' not in account, creating it');
            try {
                $result = $s3Client->createBucket([
                    'Bucket' => $this->option('bucket-name'),
                    'CreateBucketConfiguration'     => [
                        'LocationConstraint'            => $this->settings['aws']['region'],
                    ],
                    'ServerSideEncryption'          => 'aws:kms'
                ]);
            } catch (AwsException $e) {
                // output error message if fails
                $this->error('Bucket ' . $this->option('bucket-name') . ' could not be created');
                echo $e->getMessage();
                echo "\n";
                exit(1);
            }

            $this->info('Created bucket ' . $this->option('bucket-name'));

            // Enable versioning on the bucket
            try {
                $result = $s3Client->putBucketVersioning([
                    'Bucket' => $this->option('bucket-name'),
                    'VersioningConfiguration' => [
                        'Status' => 'Enabled',
                    ],
                ]);
            } catch (AwsException $e) {
                // output error message if fails
                $this->error('Bucket ' . $this->option('bucket-name') . ' could not enable versioning');
                echo $e->getMessage();
                echo "\n";
                exit(1);
            }

            $this->info('Enabled versioning on bucket ' . $this->option('bucket-name'));
        }

        // Check whether the iam grantee has a matching IAM policy allowing them access to the bucket
        $iamClient = new IamClient([
            'region' => $this->settings['aws']['region'],
            'version' => '2010-05-08',
            'credentials' => [
                'key'    => $this->settings['aws']['awsAccessKeyId'],
                'secret' => $this->settings['aws']['awsSecretAccessKey'],
            ],
        ]);

        $policyName = 's3-' . $this->option('bucket-name') . '-' . $this->option('iam-grantee');

        // See if the policy exists that joins this grantee and this bucket
        try {
            $result = $iamClient->getPolicy([
                'PolicyArn' => 'arn:aws:iam::' . $this->settings['aws']['accountId'] . ':policy/' . $policyName,
            ]);
            $this->info('Policy ' . $policyName . ' exists');
        } catch (AwsException $e) {
            // Policy probably doesn't exist, try and create it
            $this->info('Policy ' . $policyName . ' does not exist, creating it');

            try {

                // Define the IAM policy
                $policy = array(
                    'Version'   => '2012-10-17',
                    'Statement' => array(
                        array(
                            'Sid'       => 'ListBuckets',
                            'Effect'    => 'Allow',
                            'Action'    => array(
                                's3:ListAllMyBuckets',
                            ),
                            'Resource'  => '*',
                        ),
                        array(
                            'Sid'       => 'Root',
                            'Effect'    => 'Allow',
                            'Action'    => array(
                                's3:*',
                            ),
                            'Resource'  => 'arn:aws:s3:::' . $this->option('bucket-name'),
                        ),
                        array(
                            'Sid'       => 'Wildcard',
                            'Effect'    => 'Allow',
                            'Action'    => array(
                                's3:*',
                            ),
                            'Resource'  => 'arn:aws:s3:::' . $this->option('bucket-name') . '/*',
                        )
                    ),
                );

                $result = $iamClient->createPolicy([
                    'Description' => 'Kbuild policy for user ' . $this->option('iam-grantee') . ' and bucket ' . $this->option('bucket-name'),
                    'PolicyDocument' => json_encode($policy),
                    'PolicyName' => $policyName,
                ]);

                $this->info('Created IAM policy ' . $policyName);

            } catch (AwsException $e) {
                // output error message if fails
                $this->error('Policy ' . $policyName . ' not in account and an error was thrown when attempting to create it');
                echo $e->getMessage();
                echo "\n";
                exit(1);
            }

        }

        // See if the policy is attached to the iam-grantee, if not, attach it
        $result = $iamClient->listAttachedUserPolicies([
            'UserName' => $this->option('iam-grantee')
        ]);

        $this->info('Retrieved IAM policies for ' . $this->option('iam-grantee'));

        $policyAttached = false;

        // Run through the policies and check the names
        if (count($result['AttachedPolicies']) > 0) {
            foreach ($result['AttachedPolicies'] as $key => $policy) {
                if ($policy['PolicyName'] === $policyName) {
                    $policyAttached = true;
                }
            }
        }

        // If policy not attached attach it
        if ($policyAttached === false) {
            $result = $iamClient->attachUserPolicy([
                'PolicyArn' => 'arn:aws:iam::' . $this->settings['aws']['accountId'] . ':policy/' . $policyName,
                'UserName' => $this->option('iam-grantee')
            ]);
            $this->info('Attached policy ' . $policyName . ' to ' . $this->option('iam-grantee'));
        }
        else {
            $this->info('Policy ' . $policyName . ' already attached to ' . $this->option('iam-grantee'));
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