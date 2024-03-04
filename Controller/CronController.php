<?php
namespace Fontai\Bundle\CronBundle\Controller;

use App\Model;
use DateTime;
use GuzzleHttp\Client;
use Propel\Runtime\ActiveQuery\Criteria;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;


class CronController extends AbstractController
{
  protected static $timeLimit = 300;

  public function run(Request $request)
  {
    if ($request->getClientIp() != $request->server->get('SERVER_ADDR'))
    {
      throw $this->createAccessDeniedException();
    }

    set_time_limit(self::$timeLimit);

    $response = new StreamedResponse();
    $response->setCallback(function()
    {
      while(@ob_end_clean());

      $startTime = (new \DateTime())->format('U');

      $minuteLoops = self::$timeLimit / 60;
      $minTaskTime = 5;

      for ($loop = 0; $loop < $minuteLoops; $loop++)
      {
        if (!$loop && ($cron = $this->getLongPeriodCron()))
        {
          $this->runCron($cron);
        }
        else
        {
          $taskIndex = 0;

          while ($cron = $this->getShortPeriodCron())
          {
            $this->runCron($cron);

            if (++$taskIndex == floor(60 / $minTaskTime))
            {
              break;
            }
            
            $sleepTime = $startTime + ($loop * 60) + ($taskIndex * $minTaskTime) - (new \DateTime())->format('U');
            $this->notify(sprintf('Waiting %d seconds for next cron', $sleepTime));

            sleep($sleepTime);
          }
        }

        if ($loop < $minuteLoops - 1)
        {
          $sleepTime = $startTime + ($loop * 60) + 60 - time();
          $this->notify(sprintf('Waiting %d seconds for next loop', $sleepTime));

          sleep($sleepTime);
        }
      }

      $this->notify('Loops finished');
    });

    return $response;
  }

  public function task(
    Request $request,
    KernelInterface $kernel,
    int $id
  )
  {
    set_time_limit(self::$timeLimit);
    
    $cron = $this->getCommonQuery()
    ->filterById($id)
    ->findOne();

    if (!$cron)
    {
      throw $this->createNotFoundException();
    }

    $cron
    ->setPriorityRun(FALSE)
    ->setLastRunAt('now')
    ->setLastRunEndAt(NULL)
    ->save();

    $application = new Application($kernel);
    $application->setAutoExit(FALSE);

    $input = new ArrayInput([
      'command' => $cron->getCommand()
    ]);

    $output = new BufferedOutput();

    try
    {
      $application->run($input, $output);
    }
    catch (\Throwable $e)
    {
      $cron->setLastIsError(TRUE);

      $cronError = new Model\CronError();
      $cronError
      ->setCron($cron)
      ->setError($e->getMessage())
      ->save();
    }

    $cron
    ->setLastRunEndAt('now')
    ->setLastIsError(FALSE)
    ->save();

    return new Response($output->fetch());
  }

  protected function runCron(Model\Cron $cron, int $timeout = 1)
  {
    $this->notify('Running ' . htmlspecialchars($cron->getName()));

    $url = $this->generateUrl(
      'cron_task',
      ['id' => $cron->getId()],
      UrlGeneratorInterface::ABSOLUTE_URL
    );
    
    $client = new Client();
    
    try
    {
      $client->request(
        'GET',
        $url,
        [
          'verify' => FALSE,
          'timeout' => $timeout
        ]
      ); 
    }
    catch (\Exception $e)
    {}
  }

  protected function notify(string $message)
  {
    $now = new \DateTime();

    echo sprintf(
      "%s %s<br />\n",
      $now->format('Y-m-d H:i:s'),
      $message
    );

    flush();
  }

  protected function getShortPeriodCron()
  {
    $now = (new \DateTime());

    return $this->getCommonQuery()
    ->where('Cron.Type = 0')
    ->where(
      '(Cron.PriorityRun OR Cron.LastRunAt IS NULL OR Cron.LastRunAt < ? - INTERVAL Cron.Interval MINUTE + INTERVAL 1 SECOND)',
      $now->format('Y-m-d H:i:s'),
      \PDO::PARAM_STR
    )
    ->findOne();
  }

  protected function getLongPeriodCron()
  {
    $now = (new \DateTime());

    return $this->getCommonQuery()
    ->filterByType(0, Criteria::NOT_EQUAL)
    ->where(sprintf(
      '(
        CASE  
          WHEN Cron.Type = 1 THEN %d = Cron.Interval
          WHEN Cron.Type = 2 THEN %d = Cron.Interval
          WHEN Cron.Type = 3 THEN IF(
            Cron.Interval REGEXP \'[0-9]+\',
            %d = Cron.Interval,
            %d = %d
          )
        END
      )',
      $now->format('G'),
      $now->format('w') + 1,
      $now->format('j'),
      $now->format('t'),
      $now->format('j')
    ))
    ->where(sprintf(
      '(
        Cron.LastRunAt IS NULL
        OR CASE  
          WHEN Cron.Type = 1 THEN DATE(Cron.LastRunAt) != \'%s\'
          WHEN Cron.Type = 2 THEN YEARWEEK(Cron.LastRunAt) != YEARWEEK(\'%s\')
          WHEN Cron.Type = 3 THEN EXTRACT(YEAR_MONTH FROM Cron.LastRunAt) != EXTRACT(YEAR_MONTH FROM \'%s\')
        END
      )',
      $now->format('Y-m-d'),
      $now->format('Y-m-d'),
      $now->format('Y-m-d')
    ))
    ->findOne();
  }

  protected function getCommonQuery()
  {
    $now = new \DateTime('now');

    $query = Model\CronQuery::create()
    ->filterByActive(TRUE)
    ->filterByActiveFrom(NULL)
    ->_or()
    ->filterByActiveFrom(['max' => $now])
    ->filterByActiveTo(NULL)
    ->_or()
    ->filterByActiveTo(['min' => $now])
    ->where(
      '(Cron.Type > 1 OR Cron.Days LIKE CONCAT(\'%| \', ?, \' |%\'))',
      $now->format('w') + 1
    )
    // Protection against multiple running of on cron task
    ->where(
      '(Cron.LastRunAt IS NULL OR Cron.LastRunEndAt >= Cron.LastRunAt OR Cron.LastRunAt < ? - INTERVAL 20 MINUTE)',
      $now->format('Y-m-d H:i:s'),
      \PDO::PARAM_STR
    )
    ->orderByPriorityRun(Criteria::DESC)
    ->orderByLastRunAt();

    return $query;
  }
}