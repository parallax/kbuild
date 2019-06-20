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

class k8s extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'k8s';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Created a k8s folder and associated config files for various commonly used applications';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {

        $buildDirectory = getcwd();

        $application = $this->menu('Which kind of application is this?')
        ->addOption('Laravel 5.8', 'Laravel 5.8')
        ->addOption('Exit', 'Exit')
        ->setForegroundColour('black')
        ->setBackgroundColour('white')
        ->disableDefaultItems()
        ->open();

        if ($application === 'Exit') {exit;}

        $this->info("Working from directory $buildDirectory");

        if (file_exists($buildDirectory . '/k8s')) {
            $overwrite = $this->menu('You already have a k8s folder')
            ->addOption('overwrite', 'Yes, delete, update and overwrite my stuff')
            ->addOption('Exit', 'Exit')
            ->setForegroundColour('black')
            ->setBackgroundColour('white')
            ->disableDefaultItems()
            ->open();

            if ($overwrite === 'Exit') {exit;}
        }

        $this->info("Adding k8s folder and configs for $application");

        // Assets array
        $assets = array(
            'Laravel 5.8' => array(
                'kbuild.yaml' => '/k8s/kbuild.yaml'
            ), 
        );

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