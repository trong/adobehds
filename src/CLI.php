<?php

namespace AdobeHDS;

class CLI
{
    protected static $ACCEPTED = array();
    var $params = array();

    /**
     * @param int $argc
     * @param array $argv
     * @param bool|array $options
     * @param bool $handleUnknown
     */
    function __construct($argc, $argv, $options = false, $handleUnknown = false)
    {
        if ($options !== false)
            self::$ACCEPTED = $options;

        // Parse params
        if ($argc > 1) {
            $paramSwitch = false;
            for ($i = 1; $i < $argc; $i++) {
                $arg = $argv[$i];
                $isSwitch = preg_match('/^-+/', $arg);

                if ($isSwitch)
                    $arg = preg_replace('/^-+/', '', $arg);

                if ($paramSwitch and $isSwitch)
                    $this->error("[param] expected after '$paramSwitch' switch (" . self::$ACCEPTED[1][$paramSwitch] . ')');
                else if (!$paramSwitch and !$isSwitch) {
                    if ($handleUnknown)
                        $this->params['unknown'][] = $arg;
                    else
                        $this->error("'$arg' is an invalid option, use --help to display valid switches.");
                } else if (!$paramSwitch and $isSwitch) {
                    if (isset($this->params[$arg]))
                        $this->error("'$arg' switch can't occur more than once");

                    $this->params[$arg] = true;
                    if (isset(self::$ACCEPTED[1][$arg]))
                        $paramSwitch = $arg;
                    else if (!isset(self::$ACCEPTED[0][$arg]))
                        $this->error("there's no '$arg' switch, use --help to display all switches.");
                } else if ($paramSwitch and !$isSwitch) {
                    $this->params[$paramSwitch] = $arg;
                    $paramSwitch = false;
                }
            }
        }

        // Final check
        foreach ($this->params as $k => $v)
            if (isset(self::$ACCEPTED[1][$k]) and $v === true)
                $this->error("[param] expected after '$k' switch (" . self::$ACCEPTED[1][$k] . ')');
    }

    function displayHelp()
    {
        Utils::LogInfo("You can use script with following switches:\n");
        foreach (self::$ACCEPTED[0] as $key => $value)
            Utils::LogInfo(sprintf(" --%-17s %s", $key, $value));
        foreach (self::$ACCEPTED[1] as $key => $value)
            Utils::LogInfo(sprintf(" --%-9s%-8s %s", $key, " [param]", $value));
    }

    function error($msg)
    {
        Utils::LogError($msg);
    }

    function getParam($name)
    {
        if (isset($this->params[$name]))
            return $this->params[$name];
        else
            return false;
    }
}