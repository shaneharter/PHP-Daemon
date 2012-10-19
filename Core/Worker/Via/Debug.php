<?php
if (!$matches && preg_match('/^show local (\d+)/i', $input, $matches) == 1) {
    if (!is_array($this->calls)) {
        echo "No Calls In Memory", PHP_EOL;
        continue;
    }

    if (isset($this->calls[$matches[1]]))
        $message = print_r(@$this->calls[$matches[1]], true);
    else
        $message = "Item Does Not Exist";
}

if (!$matches && preg_match('/^show[\s]*(\d+)?$/i', $input, $matches) == 1) {
    if (empty($this->shm)) {
        echo "Shared Memory Not Connected Yet", PHP_EOL;
        continue;
    }

    if (count($matches) == 1) {
        $id = 1; // show the header
    } else {
        $id = $matches[1];
    }

    $message = print_r(@shm_get_var($this->shm, $id), true);
}

if (!$matches && preg_match('/^signal (\d+)/i', $input, $matches) == 1) {
    posix_kill(Core_Daemon::get('parent_pid'), $matches[1]);
    $message = "Signal Sent";
}

if (!$matches && preg_match('/^skipfor (\d+)/i', $input, $matches) == 1) {
    $time = time() + $matches[1];
    $state("skip_until", $time);
    $message = "Skipping Breakpoints for $matches[1] seconds. Will resume at " . date('H:i:s', $time);
}

if (!$matches && preg_match('/^call ([A-Z_0-9]+) (.*)?/i', $input, $matches) == 1) {
    if (count($matches) == 3) {
        $args = str_replace(',', ' ', $matches[2]);
        $args = explode(' ', $args);
    }

    $context = ($this instanceof Core_Worker_Debug_ObjectMediator) ? $this->object : $this;
    $function = array($context, $matches[1]);

    if (is_callable($function))
        if (call_user_func_array($function, $args) === true)
            $message = $break = true;
        else
            $message = "Function Not Callable!";
}

if (!$matches && preg_match('/^eval (.*)/i', $input, $matches) == 1) {
    $return = @eval($matches[1]);
    if ($return === false)
        $message = "eval returned false -- possibly a parse error. Check semi-colons, parens, braces, etc.";
    elseif ($return !== null)
        $message = "eval() returned:" . PHP_EOL . print_r($return, true);
    echo PHP_EOL;
}