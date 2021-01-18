<?php
$buildRoot = __DIR__;
$phar = new Phar($buildRoot . '/build/github2azure.phar', 0, 'github2azure.phar');
$include = '/^(?=(.*src|.*bin|.*vendor))(.*)$/i';
$phar->buildFromDirectory($buildRoot, $include);
$phar->setStub("#!/usr/bin/env php\n" . $phar->createDefaultStub("bin/github2azure"));
