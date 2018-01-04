<?php
namespace Backup\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use GuzzleHttp\Client;

class LogsCommand extends Command
{
    protected function configure()
    {
      $this
       // the name of the command (the part after "bin/console")
       ->setName('logs')

       // the short description shown while running "php bin/console list"
       ->setDescription('Downloads and stores the latest logs from govCMS SaaS')
       ->addOption(
        'api-username',
        null,
        InputOption::VALUE_REQUIRED,
        'The Acquia Cloud API username'
      )
      ->addOption(
       'api-key',
       null,
       InputOption::VALUE_REQUIRED,
       'The Acquia Cloud API key'
     )
      ->addOption(
          'destination',
          null,
          InputOption::VALUE_REQUIRED,
          'The Destination for log files'
      )
     ->addOption(
      'simulate',
      's',
      InputOption::VALUE_NONE,
      'Simulate any actions carried out on log downloading'
    );;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = new Client([
          'base_uri' => 'https://cloudapi.acquia.com/v1/',
          'auth' => [$input->getOption('api-username'), $input->getOption('api-key')],
        ]);

        $sites = json_decode($client->request('GET', 'sites.json')->getBody());
        //$sites = array_filter($sites, function ($site) {
        //  return strpos($site, 'enterprise-g1:govcms') === 0;
        //});
        $backup_locations = [];

        foreach ($sites as $site) {
          list($stage, $sitegroup) = explode(':', $site);
          $endpoint = implode('/', ['sites', $site, 'envs.json']);
          $envs = json_decode($client->request('GET', $endpoint)->getBody());

          var_dump($envs);
          $envs = array_filter($envs, function ($env) {
            return strpos($env->name, 'live') !== FALSE;
          });

          foreach ($envs as $env) {
            $endpoint = implode('/', ['sites', $site, 'envs', $env->name, 'servers.json']);
            $servers = json_decode($client->request('GET', $endpoint)->getBody());

            $servers = array_filter($servers, function ($server) {
              return !empty($server->services->web);
            });

            foreach ($servers as $server) {
              $backup_locations[] = $sitegroup . '.' . $env->name . '@' . $server->fqdn . ':' . '/var/log/sites/' . $sitegroup . '.' . $env->name . '/logs/' . $server->name;
            }
          }
        }

        print "\nFound ".sizeof($backup_locations)." log locations.\n";
        $one_day_ago = date("Ymd", time() - 60 * 60 * 24);
        foreach($backup_locations as $location) {
            print "Retrieving logs from ".$location."\n\n";
            exec("rsync -az -e \"ssh -oStrictHostKeyChecking=no -oUserKnownHostsFile=/dev/null\" ".$location." ".$input->getOption('destination')." --include=\"*".$one_day_ago.".gz\" --include=\"*/\" --exclude='*'");
        }

        print "\nDeleting older files";

        $week_ago = date("Ymd", time() - 60 * 60 * 24 * 35);

        $dir_iterator = new \RecursiveDirectoryIterator($input->getOption('destination'));
        $iterator = new \RecursiveIteratorIterator($dir_iterator, \RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $file) {
            if($this->endsWith($file, $week_ago.".gz")) {
                print "\nDeleting ".$file." as it matches ".$week_ago.".gz";
                unlink($file);
            }
        }


    }

    function endsWith($haystack, $needle) {
        $length = strlen($needle);

        return $length === 0 ||
            (substr($haystack, -$length) === $needle);
    }
}

 ?>
