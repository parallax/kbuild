<?php

namespace App\Commands;

class MySQLProvisioner {

    protected $app;
    protected $branch;
    protected $taskSpooler;
    protected $cloudProvider;
    protected $details;

    public function __construct($args) {
        $this->app = $args['app'];
        $this->branch = $args['branch'];
        $this->taskSpooler = $args['taskSpooler'];
        $this->cloudProvider = $args['cloudProvider'];
        $this->dbPerBranch = $args['dbPerBranch'];
        $this->pause = $args['pause'];
        $this->environment = $args['environment'];
        $this->rdsSg = $args['rds-sg'];
        $this->awsVpc = $args['aws-vpc'];
        $this->rdsSubnetGroup = $args['rds-subnet-group'];
        $this->salt = $args['salt'];
    }

    public function declare() {
        switch ($this->cloudProvider) {
            case 'aws':

                // Check whether we're using a db per branch
                if ($this->dbPerBranch === FALSE) {
                    $dbName = $this->app . '-' . $this->environment;
                }
                else {
                    $dbName = $this->app . '-' . $this->branch . '-' . $this->environment;
                }

                // Check whether the database exists. Set to null if it doesn't exist.
                $existingDatabase = json_decode(`aws rds describe-db-clusters --db-cluster-id=$dbName`);

                if ($existingDatabase === NULL) {
                    // Database doesn't exist

                }

                else {
                    // Database already exists

                }

                break;
            
            case 'gcp':
                # code...
                break;
        }
    }
}

?>