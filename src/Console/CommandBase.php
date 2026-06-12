<?php

namespace Framework\Console;

use Framework\Collections\Collection;

use function Framework\collection;

abstract class CommandBase
{
    /**
     * @var string
     */
    protected $summary;

    /**
     * @var string
     */
    protected $description;

    /**
     * @var array
     */
    protected $args = [];

    /**
     * @var 'after_wp_load' | 'before_wp_load'
     */
    protected $when;

    /**
     * @var Collection<Synopsis>
     */
    protected $synopsis;

    /**
     * Initialize the command
     *
     * @return void
     */
    public function __construct()
    {
        $this->synopsis = collection();
        $this->prepare();
    }

    /**
     * Run the command
     *
     * @param array $args
     * @param array $assoc
     *
     * @return void
     */
    abstract protected function run($args, $assoc);

    /**
     * Prepare the command
     *
     * @return void
     */
    protected function prepare()
    {
        //
    }

    /**
     * Check if the command passed the validation
     *
     * @param array $args
     * @param array $assoc
     *
     * @return bool
     */
    protected function passed($args, $assoc)
    {
        return true;
    }

    /**
     * Get the stub path
     *
     * @return string
     */
    protected function stub_path()
    {
        return __DIR__ . '/stubs';
    }

    protected function cli_error(string $message)
    {
        call_user_func(['\\WP_CLI', 'error'], $message);
    }

    protected function cli_success(string $message)
    {
        call_user_func(['\\WP_CLI', 'success'], $message);
    }

    protected function cli_line(string $message)
    {
        call_user_func(['\\WP_CLI', 'line'], $message);
    }

    /**
     * Set the command summary
     *
     * @param string $summary
     *
     * @return $this
     */
    protected function summary($summary)
    {
        $this->summary = $summary;

        return $this;
    }

    /**
     * Set the command description
     *
     * @param string $description
     *
     * @return $this
     */
    protected function description($description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Set the command synopsis
     *
     * @param Synopsis $synopsis
     *
     * @return $this
     */
    protected function synopsis(Synopsis $synopsis)
    {
        $this->synopsis->push($synopsis);

        return $this;
    }

    /**
     * Set the command when
     *
     * @param 'after_wp_load' | 'before_wp_load' $when
     *
     * @return $this
     */
    protected function when($when)
    {
        $this->when = $when;

        return $this;
    }

    /**
     * Get the command arguments
     *
     * @return array
     */
    public function args()
    {
        $args = [];

        if ($this->summary) {
            $args['shortdesc'] = $this->summary;
        }

        if ($this->description) {
            $args['longdesc'] = $this->description;
        }

        if (!$this->synopsis->is_empty()) {
            $args['synopsis'] = $this->synopsis->map(
                function (Synopsis $synopsis) {
                    return $synopsis->to_array();
                }
            )->all();
        }

        return $args;
    }

    /**
     * Run the command
     *
     * @param array $args
     * @param array $assoc
     *
     * @return void
     */
    public function __invoke($args, $assoc)
    {
        if (!$this->passed($args, $assoc)) {
            $this->cli_error('Command failed to pass validation. Please check the arguments and try again.');
        }

        $start = microtime(true);

        $this->run($args, $assoc);

        $runtime = number_format((microtime(true) - $start) * 1000);
        $this->cli_line(sprintf("Time: %sms", $runtime));
    }
}
