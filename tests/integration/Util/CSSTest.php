<?php
namespace MailPoet\Test\Util;

class CSSTest extends \MailPoetTest {
  public function _before() {
    $this->css = new \MailPoet\Util\CSS();
  }

  // tests
  public function testItCanBeInstantiated() {
    expect_that($this->css instanceof \MailPoet\Util\CSS);
  }
}