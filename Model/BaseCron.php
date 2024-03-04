<?php
namespace Fontai\Bundle\CronBundle\Model;


abstract class BaseCron
{
  public function __construct()
  {
  }
  
  public function getNextRunAt()
  {
    if ($this->getType() == 1)
    {
      return new \DateTime(sprintf(
        '%s %d:00:00',
        $this->getLastRunAt() > new \DateTime('today 00:00:00') ? 'tomorrow' : 'today',
        $this->getInterval()
      ));
    }
  }
}
