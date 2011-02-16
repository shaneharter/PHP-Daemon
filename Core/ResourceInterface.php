<?php

interface Core_ResourceInterface
{
	public function setup();
	public function check_environment();
	public function teardown();
}