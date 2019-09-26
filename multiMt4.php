<?php

require "vendor/autoload.php";
use PHPHtmlParser\Dom;

function print_r2($a, $out=false)
{
    $outStr = "";
    if ($out)
    {
        $outStr =  "<pre>" . print_r($a, true) . "</pre>";
        return $outStr;
    }

    echo "<pre>";
    echo print_r($a, true);
    echo "</pre>";
}

function pt(string $s)
{
    echo "[" . (new DateTime())->format("Y-m-d H:i:s") . "] ".$s."\n";
}

class MultiMt4
{
    private static $backTesterIni =
    [
        // "; common settings 
        "Login" => "",
        "Password" => "",
        "Server" => "",

        //; experts settings 
        "ExpertsEnable" => "true",
        "ExpertsDllImport" => "true",
        "ExpertsExpImport" => "true",
        "ExpertsTrades" => "true",

        //; start strategy tester 
        "TestExpert" => "NNFX_Backtest",
        "TestExpertParameters" => "NNFX_Backtest.ini",
        "TestSymbol" => null,
        "TestPeriod" => "D1",
        "TestModel" => "2",
        "TestSpread" => "0",
        "TestOptimization" => "true",
        "TestDateEnable" => "true",
        "TestFromDate" => "2014.06.01",
        "TestToDate" => "2019.06.01",
        "TestReport" => null,
        "TestReplaceReport" => "false",
        "TestShutdownTerminal" => "true",
    ];

    private static $configTemplate =
    [
        "eaIniFile" => "ea.ini",
        "workersLimit" => 1,
        "backTesterIni" =>
        [
            "Login" => "12345",
            "Password" => "67890",
            "Server" => "broker server",
            "TestFromDate" => "2014.06.01",
            "TestToDate" => "2019.06.01",
        ],
        "mt4Paths" =>
        [
        ],
        "workers" => 
        [
        ],
    ];
    private static $configPath = __DIR__."/config.json";
    private static $config = [];

    private static $user = "";
    private static $terminalConfig = "terminal.ini";

    private static $resultsList = [];
    private static $resultsPathTemplate = "results\\[{INDICATOR}]_{PAIR}_{FROMDATE}_{TODATE}-{STARTDATE}";

    private static $indisAlreadyRun = [];

    private static $firstStart = true;

    // go!
    public static function init()
    {
        // get logged in user on windows
        self::$user = trim(explode("\\", shell_exec("wmic computersystem get username"))[1]);

        self::loadConfig();

        echo "┌─────────────────────────┐\n";
        echo "│        MULTI MT4        │\n";
        echo "└─────────────────────────┘\n";
        echo "- Workers available: ".count(self::$config["workers"])."\n";
        echo "- Workers to run at same time: ".self::$config["workersLimit"]."\n\n";

        self::loop();
    }

    // script loop
    private static function loop()
    {
        $indicators = Indicator::factory(true);

        if (count($indicators) === 0)
        {
            pt("There are 0 indicators to run or they already ran.");
            die();
        }

        $indi = null;

        foreach (self::$config["workers"] as $key => $value)
        {
            self::checkTerminalAlreadyOpened($key);
        }

        // loop
        while (true)
        {
            if ($indi === null)
            {
                self::checkNewConfigsToRun($indicators);

                if (count($indicators) === 0)
                {
                    while (!self::checkWorkersDone())
                    {
                        sleep(1);
                    }

                    self::convertResultsToCsv();
                    pt("All tests done! Check 'results' folder.");
                    die();
                }

                $indi = array_pop($indicators);
            }
            else
            {
                // check workers done, so we release threads and process their results
                self::checkWorkersDone();
            }

            if (self::isMaxWorkersRunning())
            {
                self::convertResultsToCsv(1);
                sleep(1);
                continue;
            }

            $pair = $indi->getNextPair();

            if ($pair === null)
            {
                // indicator is done testing, move to the next one!
                $indi->setRun(false);
                self::$indisAlreadyRun[] = $indi->getName();
                $indi = null;
                continue;
            }

            $workerId = self::getAvailableWorkerId();

            // all workers busy
            if ($workerId === null)
            {
                // while we wait for available workers, process results to csv (if needed)
                self::convertResultsToCsv(1);
                sleep(1);
                continue;
            }

            // start next tests
            self::startWorker($indi, $workerId, $pair);
        }
    }

    // set worker EA and backtester ini files and starts the worker
    private static function startWorker(Indicator $indi, int $workerId)
    {
        $pair = $indi->getCurrentPair();
        $indicatorName = $indi->getName();

        self::setEASettings($workerId, $indi->getEaConfig());

        self::setBackTesterSettings($indi, $workerId);

        self::deleteTesterCacheFolder($workerId);

        $worker = &self::$config["workers"][$workerId];

        $cmd = '"'.$worker["terminalPath"].'terminal.exe" "'.$worker["terminalConfig"].'"';
        $pid = self::runBackgroundProcess($cmd, $workerId);
        
        if ($pid !== false)
        {
            self::$config["workers"][$workerId]["pid"] = $pid;

            self::saveConfig();
            //$indi->addWorker($workerId);
            pt("[Worker:$workerId][pid:$pid] Worker started: Indicator[".$indicatorName."], Pair[".$pair."]");

            while (!self::pidExists($pid))
            {
                $exists = self::pidExists($pid);
                //pt("pid $pid exists? ".($exists ? "yes" : "no"));
                sleep(1);
            }
        }
        else
        {
            die();
        }

        sleep(1);
    }

    // total number of workers running
    private static function isMaxWorkersRunning()
    {
        $ids = array_filter(array_column(self::$config["workers"], "pid"), function($a)
        {
            return $a !== null;
        });
        
        return count($ids) >= self::$config["workersLimit"];
    }

    // grab an available worker
    private static function getAvailableWorkerId()
    {
        $ids = array_filter(array_column(self::$config["workers"], "pid"), function($a)
        {
            return $a === null;
        });
        $ids = array_keys($ids);

        if (count($ids) === 0)
            return null;

        return $ids[0];
    }

    // creates worker settings data struct
    private static function setWorkerSettings(string $value)
    {   
        self::$config["workers"][] = 
        [
            "terminalPath" => $value,
            "dataPath" => "C:\Users\\".self::$user."\AppData\Roaming\MetaQuotes\Terminal\\".self::getHashFolder($value)."\\",
            "pid" => null,
            "lastResultPath" => null,
        ];
    }

    // set ea settings: indicator name, inputs, indexes, etc
    private static function setEASettings(int $workerId, array $newEaConfig)
    {
        $worker = &self::$config["workers"][$workerId];

        $eaIniPath = $worker["dataPath"] . "tester\\" . self::$config["eaIniFile"];
        $eaIniData = file_get_contents($eaIniPath);

        if (!preg_match_all("/<(\w+)>/", $eaIniData, $matches))
        {
            pt("Failed to load EA ini file: '$eaIniPath'");
            die();
        }

        // process format into php arrays
        $settings = [];
        foreach ($matches[1] as $key => $value)
        {
            // common, inputs, limits
            $startTagPos = strpos($eaIniData, "<$value>");
            if ($startTagPos === false)
            {
                pt("Failed to find <$value> tag on ini file: '$eaIniPath'");
                die();
            }

            $endTagPos = strpos($eaIniData, "</$value>");
            if ($endTagPos === false)
            {
                pt("Failed to find </$value> tag on ini file: '$eaIniPath'");
                die();
            }

            //if (!isset($settings[$value]))
            $settings[$value] = [];

            $startTagPos += strlen("<$value>");
            $endTagPos -= $startTagPos;

            $tagData = substr($eaIniData, $startTagPos, $endTagPos);
            $tagData = explode("\n", $tagData);

            foreach ($tagData as $data)
            {
                if (strpos($data, "=") === false)
                    continue;

                $split = explode("=", $data);
                $settings[$value][$split[0]] = trim($split[1]);

                if (isset($newEaConfig[$value]) && isset($newEaConfig[$value][$split[0]]))
                    $settings[$value][$split[0]] = trim($newEaConfig[$value][$split[0]]);
            }

        }
        
        // convert to text
        $textToSave = "";
        foreach ($settings as $key => $value)
        {
            $textToSave .= "<$key>\n";

            foreach ($value as $k => $v)
            {
                $textToSave .= "$k=$v\n";
            }

            $textToSave .= "</$key>\n\n";
        }

        file_put_contents($eaIniPath, $textToSave);
    }

    // set mt4 backtester settings: EA, Pair, start and end date, optimization, etc
    private static function setBackTesterSettings(Indicator $indi, int $workerId)
    {
        $pair = $indi->getCurrentPair();
        $indicatorName = $indi->getName();
        $worker = &self::$config["workers"][$workerId];
        $settings = self::$config["backTesterIni"];

        foreach ($settings as $key => $value)
        {
            if (isset(self::$backTesterIni[$key]))
            {
                self::$backTesterIni[$key] = $value;
            }
        }

        self::$backTesterIni["TestSymbol"] = strtoupper($pair);

        $replaceTags =
        [
            "INDICATOR" => $indicatorName,
            "PAIR" => $pair,
            "FROMDATE" => $settings["TestFromDate"],
            "TODATE" => $settings["TestToDate"],
            "STARTDATE" => (new DateTime())->format("YmdHis"),
        ];

        $lastResultPath = self::$resultsPathTemplate;

        foreach ($replaceTags as $key => $value)
        {
            $lastResultPath = str_replace("{".$key."}", $value, $lastResultPath);
        }

        // set result file name
        $resultsFolder = $worker["dataPath"] . pathinfo(self::$resultsPathTemplate, PATHINFO_DIRNAME);
        if (!file_exists($resultsFolder))
            mkdir($resultsFolder);

        self::$backTesterIni["TestReport"] = $lastResultPath . ".html";
        $worker["lastResultPath"] = $worker["dataPath"] . $lastResultPath;
        /*
        $result = 
        [
            "path" => $worker["dataPath"] . $lastResultPath,
            "workerId" => $workerId,
            "pid" => null,
        ];
        self::$resultsList[] = $result;
        */
        //pt("[Worker:$workerId] Result added to list: ".print_r2($result, true));

        // save config
        $eaConfigData = [];
        foreach (self::$backTesterIni as $key => $value)
        {
            $eaConfigData[] = $key . "=" .$value;
        }
        $eaConfigData = implode("\n", $eaConfigData);

        $worker["terminalConfig"] = self::$terminalConfig;
        file_put_contents($worker["dataPath"].$worker["terminalConfig"], $eaConfigData);
    }

    // deletes mt4 backtester cache files for each currency pair & dates candles
    // from: https://stackoverflow.com/a/3349792
    private static function deleteTesterCacheFolder(int $workerId)
    {
        $worker = &self::$config["workers"][$workerId];

        $dir = $worker["dataPath"] . "tester\\caches\\";
        if (!file_exists($dir))
            return;

        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it,
                     RecursiveIteratorIterator::CHILD_FIRST);
        foreach($files as $file) {
            if ($file->isDir()){
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($dir);
    }

    // close mt4 that is already opened and is going to be started next
    private static function checkTerminalAlreadyOpened(int $workerId)
    {
        // check if theres a terminal opened for this worker

        $worker = &self::$config["workers"][$workerId];

        $wmi = new \COM('winmgmts://');
        $processes = $wmi->ExecQuery('SELECT ExecutablePath, ProcessId FROM Win32_Process WHERE Name = "terminal.exe"');

        $list = [];
        if ($processes)
        { 
            foreach ($processes as $a)
            {
                $list[] = 
                [
                    "path" => $a->ExecutablePath,
                    "pid" => $a->ProcessId,
                ];
            }
        }

        foreach ($list as $key => $value)
        {
            $path = $worker["terminalPath"] . "terminal.exe";

            if ($path === $value["path"])
            {
                shell_exec("taskkill /F /PID ".$value["pid"]);
                sleep(1);
                break;
            }
        }
    }

    // check if workers pids dont exist = worker finished
    private static function checkWorkersDone()
    {
        $allDone = true;
        foreach (self::$config["workers"] as $key => $value)
        {
            if ($value["pid"] !== null)
            {
                if (!self::pidExists($value["pid"]))
                {
                    $allDone &= true;
                    pt("[Worker:$key][pid:".$value["pid"]."] Test finished");
                    self::$config["workers"][$key]["pid"] = null;

                    if (!self::$firstStart)
                        self::$resultsList[] = self::$config["workers"][$key]["lastResultPath"];
                }
                else
                {
                    $allDone &= false;
                }
            }
        }

        self::$firstStart = false;

        return $allDone;
    }

    private static function checkNewConfigsToRun(array $indicatorsToRun)
    {
        $list = Indicator::listConfigs(true);
        $alreadyDone = array_flip(self::$indisAlreadyRun);

        $flag = false;
        foreach ($list as $key => $value)
        {
            // name, run
            if (isset($alreadyDone[$value["name"]]))
                continue;

            $flag = true;

            $indicatorsToRun[] = new Indicator($value["name"], $value["run"]);
        }
        
        return $flag;
    }

    // process the reports into csv and copy all files to \results\ folder
    public static function convertResultsToCsv(int $howMany = null)
    {
        // format: 1731;70.33;20;1.56;3.52;99.13;0.98%;73.68421053;Input1=18 ;Input2=21 ;Input3=10;ATRPeriod=14 ;Slippage=3 ;RiskPercent=2 ;TakeProfitPercent=100 ;StopLossPercent=150 ;ReopenOnOppositeSignal=1 ;OptimizationCalcType=0 ;IndicatorType=1 ;IndicatorIndex1=0 ;IndicatorIndex2=1 ;Input4=0 ;Input5=0 ;Input6=0 ;Input7=0 ;Input8=0

        $resultsFolder = __DIR__ . "\\results\\";
        if (!file_exists($resultsFolder))
            mkdir($resultsFolder);

        if ($howMany === null)
            $howMany = count(self::$resultsList);

        $removeValue = function($s)
        {
            $index = array_search($s, self::$resultsList);
            if ($index !== false)
            {
                unset(self::$resultsList[$index]);
            }
        };

        $resultsList = self::$resultsList;
        foreach ($resultsList as $key => $value)
        {
            if (!file_exists($value.".html"))
            {
                pt("Couldn't find report file on '".$value . ".html"."'");
                $removeValue($value);
                continue;
            }

            if ($howMany++ <= 0)
                break;

            try
            {
                // convert html to csv
                $data = file_get_contents($value.".html");
                if (!preg_match_all("/(<tr.*?>(.*?)<\/tr>)+/", $data, $matches))
                    continue;

                $rows = 
                [
                    "Pass;Profit;Total trades;Profit factor;Expected payoff;Drawdown $;Drawdown %;OnTester result"
                ];
                foreach ($matches[1] as $key => $v)
                {
                    if (strpos($v, "<td title=\"") === false)
                        continue;

                    if (!preg_match_all("/<td.*?>(.*?)<\/td>/", $v, $tds))
                        continue;

                    $tds = $tds[1];

                    $row = "";
                    $row .= $tds[0] .";";
                    $row .= $tds[1] .";";
                    $row .= $tds[2] .";";
                    $row .= $tds[3] .";";
                    $row .= $tds[4] .";";
                    $row .= $tds[5] .";";
                    $row .= $tds[6] ."%;";
                    $row .= $tds[7] .";";

                    preg_match_all("/title=\"(.*?)\"/", $v, $m);
                    $row .= rtrim( str_replace( "; ", " ;", $m[1][0] ), "; ");

                    $rows[] = $row;
                    if ($key > 1000)
                        break;
                }

                $csv = implode("\n", $rows);

                if (count($rows) === 0)
                    pt($value. " has 0 results!");

                // save to csv
                file_put_contents($value.".csv", $csv);

                // move .htm and .csv files 
                $newPath = $resultsFolder . array_reverse(explode("\\", $value))[0];

                copy($value.".gif", $newPath.".gif");
                copy($value.".html", $newPath.".html");
                copy($value.".csv", $newPath.".csv");

                $pi = pathinfo($newPath);
                pt("Converted test result: ".$pi["filename"].".".$pi["extension"]);

                $removeValue($value);
            }
            catch (\Exception $e)
            {
                pt(print_r2($e, true));
            }
        }
    }

    // opens mt4 terminal.exe as a background process so php can continue 
    // and doesnt need to wait for the oppened process to finish
    private static function runBackgroundProcess(string $command, $workerId)
    {
        $descriptorspec = array(
            1 => array("pipe", "r"), // stdout
            2 => array("pipe", "r") // stderr is a file to write to
        );

        $process = proc_open('start "" /B /MIN /LOW '.$command, $descriptorspec, $pipes, null, null);

        if (is_resource($process)) 
        {
            $ppid = proc_get_status($process)['pid'];

            $output = array_filter(explode(" ", shell_exec("wmic process get parentprocessid,processid | find \"$ppid\"")));
            array_pop($output);
            $pid = end($output);

            // $pipes now looks like this:
            // 0 => writeable handle connected to child stdin
            // 1 => readable handle connected to child stdout
            // 2 => readable handle connected to child stderr

            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            // It is important that you close any pipes before calling
            // proc_close in order to avoid a deadlock
            $return_value = proc_close($process);

            if ($return_value === 0)
            {
                return $pid;
            }
            else
            {
                pt("Worker $workerId FAILED to start!");
                pt("STDOUT: '$stdout'");
                pt("STDERR: '$stderr'");
                return false;
            }
        }
        else
        {
            pt("Worker $workerId NOT started!");
            return false;
        }

        return false;
    }

    // generates the data folder unique hash, based on the mt4 instalation path
    private static function getHashFolder($s)
    {
        $s = rtrim($s, "\\");
        $r = mb_convert_encoding(strtoupper($s), "UTF-16LE");
        $result = strtoupper(md5($r));

        return $result;
    }

    // loads config.json file
    private static function loadConfig(bool $reset = false)
    {
        if (!file_exists(self::$configPath) || $reset)
        {
            file_put_contents(self::$configPath, json_encode(self::$configTemplate));

            echo("\nConfig file generated (config.json). Please change the following items and then re-run this program:\n- 'mt4Paths': add all mt4 folders paths;\n- 'pairsToTest': set true to test the pair;\n- 'terminalIni': set Login, Password, Server, TestFromDate, TestToDate\n");
            die();
        }

        self::$config = json_decode(file_get_contents(self::$configPath), true);

        if (count(self::$config["mt4Paths"]) === 0 && count(self::$config["workers"]) === 0)
        {
            pt("There's no 'mt4Paths' lines on config file!");
            die();
        }

        // check if workers are valid and if they were running, check if they are done
        foreach (self::$config["workers"] as $key => $value)
        {
            $f = $value["terminalPath"];

            if (!in_array([strlen($f) - 1], ["\\", "//"]))
                $f .= "\\";

            if (!file_exists($f) || !file_exists($f."terminal.exe"))
            {
                unset(self::$config["workers"][$key]);
                pt("Mt4 not found at '$value', item removed from config");
                continue;
            }

            if (!self::pidExists($value["pid"]))
                self::$config["workers"][$key]["pid"] = null;
        }

        $terminalPathList = array_column(self::$config, "terminalPath");
        // check if terminal path exists
        foreach (self::$config["mt4Paths"] as $key => $value)
        {
            $f = $value;

            if (!in_array($f[strlen($f) - 1], ["\\", "//"]))
                $f .= "\\";

            if (!file_exists($f) || !file_exists($f."terminal.exe"))
            {
                unset(self::$config["mt4Paths"][$key]);
                pt("Mt4 not found at '$value', item removed from config");
                continue;
            }

            unset(self::$config["mt4Paths"][$key]);
            if (in_array($f, $terminalPathList))
                continue;

            self::setWorkerSettings($f);
            $terminalPathList = array_column(self::$config, "terminalPath");
        }
    }

    // save in memory config to file
    private static function saveConfig()
    {
        file_put_contents(self::$configPath, json_encode(self::$config, JSON_PRETTY_PRINT));
    }

    // check if a process id exists or not
    private static function pidExists(int $pid = null, int $tries = 1)
    {
        if ($pid == null || $tries > 3)
            return false;

        try
        {
            exec('TASKLIST /NH /FO "CSV" /FI "PID eq '.$pid.'"', $outputA );
            if (!isset($outputA) || count($outputA) === 0)
                return false;

            $outputB = explode( '","', $outputA[0] );
            if (isset($outputB[1]))
                return true;
            return false;
        }
        catch (\Exception $e)
        {
            return self::pidExists($pid, ++$tries);
        }
    }
}

class Indicator
{
    private static $indicatorsFolder = __DIR__ . "/indicators-configs/";

    private $configPath = "";
    private $config = null;
    //private $workerId = null;
    private $currentPair = null;
    private $run = false;

    public static function factory(bool $run = null)
    {
        $instances = [];
        $configsPaths = self::listConfigs($run);

        foreach ($configsPaths as $key => $value)
        {
            $instances[] = new Indicator($value["name"], $value["run"]);
        }

        return $instances;
    }

    // filter indicators configs: true = run, false = dont run, null = all
    public static function listConfigs(bool $filterRun = null)
    {
        $indicatorsFolder = self::$indicatorsFolder;
        if (!file_exists($indicatorsFolder))
            mkdir($indicatorsFolder);

        $it = new RecursiveDirectoryIterator($indicatorsFolder, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        $list = [];
        foreach($files as $file)
        {
            $pa = pathinfo($file->getFilename());
            if (!isset($pa["extension"]) || $pa["extension"] !== "json")
                continue;

            $run = false;
            if (substr($pa["filename"], -strlen(".run")) === ".run")
            {
                $run = true;
                $pa["filename"] = str_replace(".run", "", $pa["filename"]);
            }

            if ($filterRun === null || ($filterRun === true && $run) || ($filterRun === false && !$run))
                $list[] = [ "name" => $pa["filename"], "run" => $run ];
        }

        return $list;
    }

    public function __construct(string $configPath, bool $run)
    {
        $this->configPath = $configPath;
        $this->run = $run;
    }

    public function getEaConfig()
    {
        return $this->config["eaIni"];
    }

    // loads config.json file
    private function loadConfig()
    {
        if ($this->config !== null)
            return false;

        $this->config = json_decode(file_get_contents(self::$indicatorsFolder . $this->configPath . ($this->run ? ".run" : "") . ".json"), true);

        return true;
    }

    // grab the next pair to be tested
    public function getNextPair()
    {
        $configLoaded = $this->loadConfig();
        $pairsToTest = &$this->config["pairsToTest"];

        if (count($this->config["pairsToTest"]) === 0)
            return null;

        if ($configLoaded)
        {
            $pairsToTest = array_filter($pairsToTest);
            $pairsToTest = array_keys($pairsToTest);
        }

        $result = array_pop($pairsToTest);

        $this->currentPair = $result;

        return $result;
    }

    public function getCurrentPair()
    {
        return $this->currentPair;
    }

    public function getName()
    {
        return $this->configPath;
    }

    public function setRun(bool $newRun)
    {
        $prevRun = $this->run;

        $oldname = self::$indicatorsFolder . $this->getName() . ($prevRun ? ".run" : "") . ".json";
        $newname = self::$indicatorsFolder . $this->getName() . ($newRun ? ".run" : "") . ".json";

        if (rename($oldname, $newname))
            $this->run = $newRun;
    }
}

if (php_sapi_name() === "cli")
    MultiMt4::init();