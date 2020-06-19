<?php
/**
 * @copyright Copyright 2017-present Appsinet. All Rights Reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/** Uses phpstan to lint php files */
final class PhpstanLinter extends ArcanistLinter
{
    /**
     * @var array Stored/parsed results from phpstan run, keys are filenames
     */
    protected $results = array();

    /**
     * @var array Flags passed to phpstan command
     */
    protected $flags = array();

    /**
     * @var array Paths passed to phpstan command (provided by lint config)
     */
    protected $phpstanPaths = array();

    /**
     * @var string Phpstan binary to execute (optionally provided via config)
     */
    protected $bin;

    /**
     * @var bool If phpstan command has been started, this is set to true, which will prevent it from running again
     */
    protected $processing = false;

    /**
     * @var string Config file path
     */
    private $configFile = null;

    /**
     * @var string Rule level
     */
    private $level = null;

    /**
     * @var string Autoload file path
     */
    private $autoloadFile = null;

    /**
     * @var string|null|false The version of the phpstan executable if available (null = not set yet, false = can't get version)
     */
    private $phpstanVersion = null;

    public function willLintPaths(array $paths) {
        // Prevent double processing since we really only need to run phpstan once per lint run
        if ($this->processing === false) {
            $this->processing = true;

            $phpstanVersion = $this->getVersion();

            $flags = array_merge($this->getMandatoryFlags(), nonempty($this->flags, $this->getDefaultFlags()));

            $bin = csprintf('%s', $this->bin);
            $bin = csprintf('%C %Ls', $bin, $flags);

            if (empty($this->phpstanPaths)) {
                if (version_compare('0.11', $phpstanVersion) > 0) {
                    $paths = './';
                } else {
                    $paths = '';
                }
            } else {
                $paths = implode(' ', $this->phpstanPaths);
            }
            $future = new ExecFuture('%C %C', $bin, $paths);
            $future->setCWD($this->getProjectRoot());

            list($err, $stdout, $stderr) = $future->resolve();

            $this->parseLinterOutput($err, $stdout, $stderr);

            if ($err && empty($this->results)) {
                throw new Exception(
                    sprintf(
                        "%s\n\nSTDOUT\n%s\n\nSTDERR\n%s",
                        pht('Linter failed to parse output!'),
                        $stdout,
                        $stderr
                    )
                );
            }
        }
    }

    public function didLintPaths(array $paths)
    {
        foreach ($this->results as $path => $messages) {
            foreach ($messages as $message) {
                $this->addLintMessage($message);
            }
            unset($this->results[$path]);
        }
    }

    public function getInfoName()
    {
        return 'phpstan';
    }

    public function getInfoURI()
    {
        return '';
    }

    public function getInfoDescription()
    {
        return pht('Use phpstan for processing specified files.');
    }

    public function getLinterConfigurationName()
    {
        return 'phpstan';
    }

    public function getDefaultBinary()
    {
        return 'phpstan';
    }

    public function getInstallInstructions()
    {
        return pht('Install phpstan following the official guide at https://github.com/phpstan/phpstan#installation');
    }

    public function shouldExpectCommandErrors()
    {
        return true;
    }

    public function getVersion()
    {
        if ($this->phpstanVersion === null) {
            list($stdout) = execx('%C --version', $this->getExecutableCommand());

            $matches = array();
            $regex = '/(?P<version>\d+\.\d+\.\d+)/';
            if (preg_match($regex, $stdout, $matches)) {
                $this->phpstanVersion = $matches['version'];
            } else {
                $this->phpstanVersion = false;
            }
        }

        return $this->phpstanVersion;
    }

    protected function getMandatoryFlags()
    {
        $flags = array(
            'analyse',
            '--no-progress',
        );

        $phpstanVersion = $this->getVersion();

        if (version_compare('0.11', $phpstanVersion) <= 0) {
            array_push($flags, '--error-format=checkstyle');
        } else {
            array_push($flags, '--errorFormat=checkstyle');
        }

        if (null !== $this->configFile) {
            array_push($flags, '-c', $this->configFile);
        }
        if (null !== $this->level) {
            array_push($flags, '-l', $this->level);
        } elseif (version_compare('0.11', $phpstanVersion) > 0) {
            array_push($flags, '-l', 'max');
        }
        if (null !== $this->autoloadFile) {
            array_push($flags, '-a', $this->autoloadFile);
        }

        return $flags;
    }

    public function getLinterConfigurationOptions()
    {
        $options = array(
            'config' => array(
                'type' => 'optional string',
                'help' => pht(
                    'The path to your phpstan.neon file. Will be provided as -c <path> to phpstan.'
                ),
            ),
            'level' => array(
                'type' => 'optional int',
                'help' => pht(
                    'Rule level used (0 loosest - max strictest). Will be provided as -l <level> to phpstan if' .
                    ' present, otherwise relies on what is specified in the phpstan config (if using phpstan 0.11 or' .
                    ' higher, defaults to "max" in older versions of phpstan).'
                ),
            ),
            'autoload' => array(
                'type' => 'optional string',
                'help' => pht(
                    'The path to the auto load file. Will be provided as -a <autoload_file> to phpstan.'),
            ),
            'bin' => array(
                'type' => 'optional string | list<string>',
                'help' => pht(
                  'Specify a string (or list of strings) identifying the binary '.
                  'which should be invoked to execute this linter. This overrides '.
                  'the default binary. If you provide a list of possible binaries, '.
                  'the first one which exists will be used.'),
            ),
            'paths' => array(
                'type' => 'optional string | list<string>',
                'help' => pht(
                    'The path(s) that phpstan should analyze (defaults to value in phpstan config, only provide to override).'
                )
            ),
            'flags' => array(
                'type' => 'optional list<string>',
                'help' => pht(
                  'Provide a list of additional flags to pass to the linter on the '.
                  'command line.'),
            ),
            'version' => array(
                'type' => 'optional string',
                'help' => pht(
                  'Specify a version requirement for the binary. The version number '.
                  'may be prefixed with <, <=, >, >=, or = to specify the version '.
                  'comparison operator (default: =).'),
            ),
        );

        return $options + parent::getLinterConfigurationOptions();
    }

    /**
     * Override the default binary with a new one.
     *
     * @param string  New binary.
     * @return $this
     * @task bin
     */
    final public function setBinary($bin) {
        $this->bin = $bin;

        return $this;
    }

    /**
     * Override default flags with custom flags. If not overridden, flags provided
     * by @{method:getDefaultFlags} are used.
     *
     * @param list<string> New flags.
     * @return this
     * @task bin
     */
    final public function setFlags(array $flags) {
        $this->flags = $flags;

        return $this;
    }

    /**
     * Provide default, overridable flags to the linter. Generally these are
     * configuration flags which affect behavior but aren't critical. Flags
     * which are required should be provided in @{method:getMandatoryFlags}
     * instead.
     *
     * Default flags can be overridden with @{method:setFlags}.
     *
     * @return list<string>  Overridable default flags.
     * @task bin
     */
    protected function getDefaultFlags() {
         return array();
    }

    public function setLinterConfigurationValue($key, $value)
    {
        switch ($key) {
            case 'config':
                $this->configFile = $value;
                return;
            case 'level':
                $this->level = $value;
                return;
            case 'autoload':
                $this->autoloadFile = $value;
                return;
            case 'flags':
                $this->setFlags($value);
                return;
            case 'paths':
                $this->phpstanPaths = (array) $value;
                return;
            case 'bin':
                $is_script = false;
                $root = $this->getProjectRoot();
                foreach ((array)$value as $path) {
                    if (!$is_script && Filesystem::binaryExists($path)) {
                        $this->setBinary($path);
                        return;
                    }
                    $path = Filesystem::resolvePath($path, $root);
                    if ((!$is_script && Filesystem::binaryExists($path)) ||
                        ($is_script && Filesystem::pathExists($path))) {
                        $this->setBinary($path);
                        return;
                    }
                }
                throw new Exception(
                    pht('None of the configured binaries can be located.'));
            default:
                parent::setLinterConfigurationValue($key, $value);
                return;
        }
    }

    protected function getDefaultMessageSeverity($code)
    {
        return ArcanistLintSeverity::SEVERITY_WARNING;
    }

    protected function parseLinterOutput($err, $stdout, $stderr)
    {
        if (!empty($stdout)) {
            $stdout = substr($stdout, strpos($stdout, '<?xml'));
            $checkstyleOutput = new SimpleXMLElement($stdout);
            $files = $checkstyleOutput->xpath('//file');
            foreach($files as $file) {
                $path = str_replace($this->getProjectRoot() . '/', '', $file['name']);
                $error = $file->error;
                if (!isset($this->results[$path])) {
                    $this->results[$path] = array();
                }
                $violation = $this->parseViolation($error);
                $violation['path'] = $path;
                $this->results[$path][] = ArcanistLintMessage::newFromDictionary($violation);
            }
        }
    }

    /**
     * Checkstyle returns output of the form
     *
     * <checkstyle>
     *   <file name="${sPath}">
     *     <error line="12" column="10" severity="${sSeverity}" message="${sMessage}" source="${sSource}">
     *     ...
     *   </file>
     * </checkstyle>
     *
     * Of this, we need to extract
     *   - Line
     *   - Column
     *   - Severity
     *   - Message
     *   - Source (name)
     *
     * @param SimpleXMLElement $violation The XML Entity containing the issue
     *
     * @return array of the form
     * [
     *   'line' => {int},
     *   'column' => {int},
     *   'severity' => {string},
     *   'message' => {string}
     * ]
     */
    private function parseViolation(SimpleXMLElement $violation)
    {
        return array(
            'code' => $this->getLinterName(),
            'name' => (string)$violation['message'],
            'line' => (int)$violation['line'],
            'char' => (int)$violation['column'],
            'severity' => $this->getMatchSeverity((string)$violation['severity']),
            'description' => (string)$violation['message']
        );
    }

    /**
     * @return string Linter name
     */
    public function getLinterName()
    {
        return 'phpstan';
    }

    /**
     * Map the regex matching groups to a message severity. We look for either
     * a nonempty severity name group like 'error', or a group called 'severity'
     * with a valid name.
     *
     * @param string $severity_name dict Captured groups from regex.
     *
     * @return string @{class:ArcanistLintSeverity} constant.
     *
     * @task parse
     */
    private function getMatchSeverity($severity_name)
    {
        $map = array(
            'error' => ArcanistLintSeverity::SEVERITY_ERROR,
            'warning' => ArcanistLintSeverity::SEVERITY_WARNING,
            'info' => ArcanistLintSeverity::SEVERITY_ADVICE,
        );
        foreach ($map as $name => $severity) {
            if ($severity_name == $name) {
                return $severity;
            }
        }

        return ArcanistLintSeverity::SEVERITY_ERROR;
    }

    /**
     * Get the composed executable command, including the interpreter and binary
     * but without flags or paths. This can be used to execute `--version`
     * commands.
     *
     * @return string Command to execute the raw linter.
     * @task exec
     */
    final protected function getExecutableCommand() {
        return $this->bin;
    }
}
