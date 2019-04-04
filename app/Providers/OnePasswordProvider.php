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
use PurplePixie\PhpDns\DNSQuery;

class OnePassword {

    protected $masterPassword;
    protected $url;
    protected $email;
    protected $secretKey;
    protected $token;
    protected $vault;
    protected $settings;

    public function __construct($args) {

        $this->masterPassword = $args['masterPassword'];
        $this->url = $args['url'];
        $this->email = $args['email'];
        $this->secretKey = $args['secretKey'];
        $this->app = $args['app'];
        $this->settings = $args['settings'];

        // Get the token
        $token = exec("echo '" . $this->masterPassword . "' | op signin " . $this->url . " " . $this->email . " " . $this->secretKey . " --output=raw --shorthand=" . md5(date('U')), $onePasswordSession, $status);
        if ($status !== 0) {
            echo "Error logging into 1password";
            exit(1);
        }
        $this->token = $token;

        // Ensure vault exists
        $vaults = exec("op list vaults " . $this->url . " --session=" . $this->token);
        $vaults = json_decode($vaults, TRUE);
        $exists = false;
        foreach ($vaults as $key => $vault) {
            if ($vault['name'] === $this->app) {
                $exists = true;
                $this->vault = $vault['uuid'];
            }
        }
        // Create vault if it doesn't exist
        if ($exists === false) {
            $this->vault = json_decode(exec("op create vault " . $this->app . " --session=" . $this->token))->uuid;
        }
    }

    private function getItems() {
        $items = exec("op list items " . $this->url . " --vault=" . $this->vault . " --session=" . $this->token);
        $items = json_decode($items, TRUE);

        if($items === null) {
            $items = array();
        }

        return ($items);

    }

    private function getUsers() {
        $users = exec("op list users " . $this->url . " --vault=" . $this->vault . " --session=" . $this->token);
        $users = json_decode($users, TRUE);

        if($users === null) {
            $users = array();
        }

        return ($users);

    }

    public function createOrUpdateDatabase($name, $type, $server, $port, $database, $username, $password) {

        // See if the item already exists in the vault
        $items = $this->getItems();

        $exists = false;
        foreach ($items as $key => $item) {
            if ($item['overview']['title'] === $name) {
                $exists = true;
            }
        }

        if ($exists === false) {

            // Do the DNS magic
            $query=new DNSQuery($this->settings['aws']['dnsProxy'],53,60,true,false,false);
            $results=$query->query($server,'A');
            

            foreach ($results as $key => $result) {
                if ($result->getTypeid() === 'A') {
                    $hostName = $result->getData();
                }
            }

            $onePasswordObject = array(
                'sections' => array(
                    array(
                        'name' => $name,
                        'fields' => array(
                            array(
                                'k' => 'menu',
                                'n' => 'database_type',
                                'v' => $type,
                                't' => 'type'
                            ),
                            array(
                                'k' => 'string',
                                'n' => 'hostname',
                                'v' => $hostName,
                                't' => 'server'
                            ),
                            array(
                                'k' => 'string',
                                'n' => 'port',
                                'v' => $port,
                                't' => 'port'
                            ),
                            array(
                                'k' => 'string',
                                'n' => 'username',
                                'v' => $username,
                                't' => 'username'
                            ),
                            array(
                                'k' => 'concealed',
                                'n' => 'password',
                                'v' => $password,
                                't' => 'password'
                            ),
                        )
                    )
                )
            );
            $onePasswordEncoded = base64_encode(json_encode($onePasswordObject));
            exec("op create item database $onePasswordEncoded --vault=" . $this->vault . " --title='$name'" . " --session=" . $this->token);
        }

        // Grant access based on who has committed
        $users = $this->getUsers();

        exec("git log --pretty='%ae' | sort | uniq", $committers, $exit);
        if ($exit !== 0) {
            echo "There was a problem listing git commits";
            exit(2);
        }

        $return = 'Added ';

        // Now cycle through the committers and see if they match up to a 1password user
        foreach ($committers as $key => $committerEmail) {
            foreach ($users as $key => $user) {
                if ($committerEmail === $user['email']) {
                    // They match! Add them to the vault
                    exec("op add " . $user['uuid'] . " " . $this->vault . " --session=" . $this->token);
                    $return .= $committerEmail . ' ';
                }
            }
        }
        echo $return;
    }
}