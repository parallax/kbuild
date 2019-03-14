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

class MySQL extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'create:mysql 
        {--app= : The name of the app}
        {--branch= : The name of the app branch}
        {--environment= : The name of the environment}
        {--cloud-provider=aws : Either aws or gcp}
        {--aws-vpc= : The VPC to use. Only used when cloud-provider is set to aws}
        {--db-pause=60 : The amount of time in minutes to pause an Aurora instance after no activity}
        {--db-per-branch : Whether to use one database per branch}
        {--use-own-db-server : Whether to use a server explicitly spun up for this app}
        {--settings= : The settings.yaml file to use}
        {--kubeconfig= : The path to the kubeconfig file}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Declare a MySQL database and/or server';

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

        //dd($this->settings);

        switch ($this->option('cloud-provider')) {
            case 'aws':

                $this->info('Running on aws');

                // Check whether we're using a db per branch
                if ($this->option('use-own-db-server') !== FALSE) {
                    // Use own database server
                    $serverName = $this->option('app') . '-' . $this->option('environment');
                    $this->info($this->option('app') . ' uses own db server');
                }
                elseif ($this->option('use-own-db-server') === FALSE) {
                    // Don't use own database server
                    $serverName = 'shared-serverless' . '-' . $this->option('environment');
                    $this->info($this->option('app') . ' uses shared db server');
                }

                // Check whether the database exists. Set to null if it doesn't exist.
                $existingServer = json_decode(`aws rds describe-db-clusters --db-cluster-id=$serverName`);

                if ($existingServer === NULL) {

                    $this->info($serverName . ' does not exist, creating');

                    // Grab the availability zones from settings
                    $availabilityZones = '';
                    foreach ($this->settings['aws']['availabilityZones'] as $key => $availabilityZone) {
                        $availabilityZones .= '"' . $availabilityZone . '" ';
                    }

                    // And the VPC security group id
                    $vpcSecurityGroupId = $this->settings['aws']['rdsSecurityGroup'];

                    $dbSubnetGroupName = $this->settings['aws']['rdsSubnetGroup'];

                    $SecondsUntilAutoPause = $this->option('db-pause') * 60;

                    $masterPassword = sha1($serverName . $this->settings['mysql']['salt']);

                    $this->info('Master Username: ' . 'master');
                    $this->info('Master Password: ' . $masterPassword);

                    // Server doesn't exist. Let's create the server and wait for it to be live:
                    $dbStatus = `aws rds create-db-cluster --availability-zones $availabilityZones --backup-retention-period=35 --db-cluster-identifier=$serverName --vpc-security-group-ids=$vpcSecurityGroupId --engine=aurora --master-username=master --master-user-password=$masterPassword --db-subnet-group-name=$dbSubnetGroupName --storage-encrypted --engine-mode=serverless --scaling-configuration='MinCapacity=2,MaxCapacity=256,AutoPause=true,SecondsUntilAutoPause=$SecondsUntilAutoPause'`;

                    if (json_decode($dbStatus, TRUE) === NULL) {
                        echo "Something has gone wrong while creating database server $serverName\n";
                        exit(1);
                    }

                    $dbStatus = `aws rds describe-db-clusters --db-cluster-id=$serverName`;
                    $dbStatus = json_decode($dbStatus, TRUE);

                    $waitingSeconds = 1;

                    while ($dbStatus['DBClusters'][0]['Status'] !== 'available') {

                        $dbStatus = `aws rds describe-db-clusters --db-cluster-id=$serverName`;
                        $dbStatus = json_decode($dbStatus, TRUE);
                        $this->info('Waiting ' . $waitingSeconds . ' seconds. ' . $serverName . ' ' . $dbStatus['DBClusters'][0]['Status']);
                        $waitingSeconds = $waitingSeconds + 1;
                        sleep(1);
                    }

                    // Db server has been created
                    // Sleep for 60 seconds to wait for DNS resolution to work
                    $waitFor = 60;
                    while($waitFor > 0) {
                        $this->info('Waiting for ' . $waitFor . ' seconds for AWS DNS resolution to work');
                        sleep(1);
                        $waitFor = $waitFor - 1;
                    }
                    $this->info('Finished waiting for DNS');
                }

                // Get the endpoint of the database
                $databaseEndpoint = json_decode(`aws rds describe-db-clusters --db-cluster-id=$serverName`, TRUE)['DBClusters'][0]['Endpoint'];

                break;
            
            case 'gcp':
                # code...
                break;
        }

        $this->info('Server ' . $serverName . ' exists, creating database and user');

        $masterPassword = sha1($serverName . $this->settings['mysql']['salt']);

        // Create the MySQL user, password and database using a container (fancy!)
        if ($this->option('db-per-branch') !== FALSE) {
            // Using db per branch, set $database accordingly
            $databaseName = $this->option('app') . '-' . $this->option('branch');
        }
        else {
            // Not using db per branch
            $databaseName = $this->option('app') . '-' . 'master';
        }

        $databaseUser = $this->option('app');
        $databasePassword = sha1($this->option('app') . $this->option('environment') . $this->settings['mysql']['salt']);

        // Apply the secret:
        $yamlArray = array(
            'apiVersion'    => 'v1',
            'kind'          => 'Secret',
            'metadata'      => array(
                'name'          => 'mysql-credentials',
                'namespace'     => $this->option('app') . '-' . $this->option('environment'),
            ),
            'type'          => 'Opaque',
            'stringData'    => array(
                'username'      => $this->option('app'),
                'password'      => $databasePassword
            )
        );

        $command = "cat <<EOF | kubectl --kubeconfig=" . $this->option('kubeconfig') . " apply -f -\n" . Yaml::dump($yamlArray) . "\nEOF";

        system($command, $exit);
        if ($exit !== 0) {
            echo "Error creating mysqlCredentials secret\n";
            exit(1);
        }

        // Run a pod in Kubernetes to create the db and user
        $run = "mysql -h $databaseEndpoint -u master --password=$masterPassword -e 'CREATE DATABASE IF NOT EXISTS `$databaseName` DEFAULT CHARACTER SET = utf8 DEFAULT COLLATE = utf8_general_ci;'";
        $command = 'kubectl -n ' . $this->option('app') . '-' . $this->option('environment') . ' --kubeconfig=' . $this->option('kubeconfig') . ' run --rm -i --tty mysql-' . $this->option('branch') . ' --image=mysql:5.6 --restart=Never -- ' . $run;
        system($command, $exit);
        if ($exit !== 0) {
            echo "Error creating MySQL database\n";
            exit(1);
        }
        $this->info('Created MySQL Database');
        $run = "mysql -h $databaseEndpoint -u master --password=$masterPassword -e 'GRANT ALL ON `$databaseName`.* TO \"$databaseUser\"@\"%\" IDENTIFIED BY \"$databasePassword\";'";
        $command = 'kubectl -n ' . $this->option('app') . '-' . $this->option('environment') . ' --kubeconfig=' . $this->option('kubeconfig') . ' run --rm -i --tty mysql-' . $this->option('branch') . ' --image=mysql:5.6 --restart=Never -- ' . $run;
        system($command, $exit);
        if ($exit !== 0) {
            echo "Error creating MySQL user\n";
            exit(1);
        }
        $this->info('Created MySQL Database and User');

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