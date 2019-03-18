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

class CreateS3Bucket extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'create:s3bucket 
        {--bucket-name= : The namespace to create/ensure exists}
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
                print_r($bucket);
            }

        }

        // Check if bucket exists
        if ($bucketExists === false) {
            $this->info('Bucket ' . $this->option('bucket-name') . ' not in account, creating it');
            try {
                $result = $s3Client->createBucket([
                    'Bucket' => $this->option('bucket-name'),
                    'CreateBucketConfiguration' => [
                        'LocationConstraint' => $this->settings['aws']['region'],
                    ],
                    'ServerSideEncryption' => 'aws:kms'
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
            } catch  (AwsException $e) {
                // output error message if fails
                $this->error('Bucket ' . $this->option('bucket-name') . ' could not enable versioning');
                echo $e->getMessage();
                echo "\n";
                exit(1);
            }

            $this->info('Enabled versioning on bucket ' . $this->option('bucket-name'));
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