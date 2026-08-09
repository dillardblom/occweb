<?php

namespace OCA\OCCWeb\Controller;

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

class OccOutput extends BufferedOutput implements ConsoleOutputInterface
{
  private $consoleSectionOutputs = [];
  private $stream;

  public function getErrorOutput(): OutputInterface
  {
    return $this;
  }

  public function setErrorOutput(OutputInterface $error)
  {
  }

  public function section(): ConsoleSectionOutput 
  {
    if ($this->stream === null) {
      $this->stream = fopen('php://temp', 'w+');
    }
    return new ConsoleSectionOutput(
      $this->stream, 
      $this->consoleSectionOutputs, 
      $this->getVerbosity(), 
      $this->isDecorated(), 
      $this->getFormatter()
    );
  }

  public function fetch(): string
  {
    // Получаем основной буфер
    $content = parent::fetch();
    
    // Если есть stream от секций, читаем его тоже
    if ($this->stream !== null) {
      rewind($this->stream);
      $streamContent = stream_get_contents($this->stream);
      if ($streamContent !== false) {
        $content .= $streamContent;
      }
      // Очищаем stream для следующего вызова
      ftruncate($this->stream, 0);
      rewind($this->stream);
    }
    
    return $content;
  }
}
