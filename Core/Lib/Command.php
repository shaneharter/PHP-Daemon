<?php
/**
 *
 * @author sharter
 */

class Core_Lib_Command
{
    public $regex;

    public $command;

    public $description;

    public $callable;

    public function match($input, Array $args = array()) {
        $matches = null;
        if (preg_match($this->regex, $input, $matches) == 1)
            return call_user_func_array($this->callable, array_shift($args, $matches));

        return null;
    }

}
