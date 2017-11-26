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
        $sites = array_filter($sites, function ($site) {
          return strpos($site, 'enterprise-g1:govcms') === 0;
        });

        $backup_locations = [];

        foreach ($sites as $site) {
          list($stage, $sitegroup) = explode(':', $site);
          $endpoint = implode('/', ['sites', $site, 'envs.json']);
          $envs = json_decode($client->request('GET', $endpoint)->getBody());

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

        print_r($backup_locations);

    }
}

 ?>
