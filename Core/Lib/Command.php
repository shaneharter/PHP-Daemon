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

    public $result_input;

    public $result;

    /**
     * When true, indicate to the calling code that $input shouldn't be tested against any additional commands. Will guarantee
     * 0 or 1 commands will match any $input.
     * @var bool
     */
    public $exclusive = true;

    /**
     * Apply the $input against the command regex. If it matches, the contained $callable will be called and its result
     * will be available in Core_Lib_Command::result. It will return true. The result will be available until match() is called again.
     * The input matched against is also available as Core_Lib_Command::result_input
     *
     * The passed array of $args is passed to the callable with an array of regex matches appened. Eg if you pass an
     * $args array with 2 values, they will be the first and second args passed into the callable, and the matches array will be the 3rd.
     *
     * @param $input
     * @param array $args
     * @return bool
     */
    public function match($input, Array $args = array()) {
        $this->result_input = $this->result = $matches = null;
        if (preg_match($this->regex, $input, $matches) == 1) {
            array_unshift($args, $matches);
            $this->result_input = $input;
            $this->result = call_user_func_array($this->callable, $args);
            return true;
        }

        return false;
    }

}
