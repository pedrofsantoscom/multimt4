<?php

session_start();

$indicatorsFolder = __DIR__ . "/indicators-configs/";

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

function getIndicatorsList()
{
    global $indicatorsFolder;

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

        $list[] = [ "name" => $pa["filename"], "run" => $run ];
    }

    return $list;
}

function exists($indicator, $run = false)
{
    global $indicatorsFolder;

    $path = $indicatorsFolder.$indicator. ($run ? ".run" : "").".json";
    $result = file_exists($path);

    return $result;
}

function convertEaSettingsToTable($config)
{
    $result = [];
    foreach ($config["eaIni"]["inputs"] as $key => $value)
    {
        $keyVal = [ "name" => $key, "value" => $value ];
        $split = explode(",", $key);
        $name = $split[0];

        if ( !isset($result[$name]) )
        {
            $result[$name] = [];
        }

        if (isset($split[1]))
        {
            switch ($split[1])
            {
                case 'F':
                    $result[$name]["optimization"] = $keyVal;
                    break;
                
                case '1':
                    $result[$name]["start"] = $keyVal;
                    break;

                case '2':
                    $result[$name]["step"] = $keyVal;
                    break;

                case '3':
                    $result[$name]["stop"] = $keyVal;
                    break;
            }
        }
        else
        {
            $result[$name]["variable"] = $keyVal;
        }
    }

    return $result;
}

function deleteConfig($indicator, $run = false)
{
    global $indicatorsFolder;

    @unlink($indicatorsFolder . $indicator . ($run ? ".run" : "") . ".json");
}

function loadDefaultConfig()
{
    global $indicatorsFolder;

    $config = json_decode(file_get_contents($indicatorsFolder . "default"), true);

    return $config;
}

function loadConfig($indicator, $run = false)
{
    global $indicatorsFolder;

    $config = json_decode(file_get_contents($indicatorsFolder . $indicator. ($run ? ".run" : "") . ".json"), true);

    return $config;
}

function saveConfig($name, $data, $run = false)
{
    global $indicatorsFolder;

    $data = json_encode($data, JSON_PRETTY_PRINT);
    if ($data == null)
        return false;

    return file_put_contents($indicatorsFolder . $name . ($run ? ".run" : "") . ".json", $data);
}

function updateConfigValues($config, $newValues)
{
    foreach ($newValues["pairsToTest"] as $key => $value)
    {
        if ($value === "1")
        {
            $newValues["pairsToTest"][$key] = true;
        }
    }
    
    $updated = array_replace_recursive($config, $newValues);

    return $updated;
}

function setIndicatorToRun($indicator, $toRun, $isRun)
{
    global $indicatorsFolder;

    $oldname = $indicatorsFolder . $indicator . ($isRun ? ".run" : "") . ".json";
    $newname = $indicatorsFolder . $indicator . ($toRun ? ".run" : "") . ".json";
    var_dump($indicator, $toRun, $isRun, $oldname, $newname); 

    return @rename($oldname, $newname);
}

function shutdownServer()
{
    $wmi = new \COM('winmgmts://');
    $processes = $wmi->ExecQuery('SELECT ExecutablePath, ProcessId FROM Win32_Process WHERE CommandLine like "%php  -S localhost:8000%"');

    $list = [];
    if ($processes)
    { 
        foreach ($processes as $a)
        {
            session_write_close();
            ignore_user_abort(true);
            ob_end_clean();

            ob_start();
            echo "Server killed, you can close this tab now.";
            @ob_flush();@flush();

            shell_exec('taskkill /F /PID '.$a->ProcessId);
            die();
        }
    }
}

function getIndicator($name, $indicators)
{
    $names = array_column($indicators, "name");
    $keys = array_flip($names);
    if (isset($keys[$name]))
        return $indicators[$keys[$name]];

    return null;
}

$config = [];
$eaIni = [];
$pairs = [];
$action = "list";
$indicator = "";
$indicatorsList = getIndicatorsList();

if (isset($_POST["action"]))
{
    $action = $_POST["action"];
    $indicator = $_POST["indicator"];

    unset($_POST["action"], $_POST["indicator"]);
    
    if ($action === "save-edit")
    {
        $indicator = base64_decode($indicator);
        $indicatorData = getIndicator($indicator, $indicatorsList);

        $toRun = $_POST["run"] === "1" ? true : false;
        unset($_POST["run"]);
        $config = loadConfig($indicator, $indicatorData["run"]);
        $updated = updateConfigValues($config, $_POST);
        saveConfig($indicator, $updated, $indicatorData["run"]);

        setIndicatorToRun($indicator, $toRun, $indicatorData["run"]);

        $_SESSION['message'] = ["type" => "success", "text" => "Indicator config '".$indicator."' saved!"];
        header("Location: ".$_SERVER["SCRIPT_NAME"]);
        die();
    }
    else if ($action === "save-new")
    {
        // indicator exists?
        if (exists($indicator))
        {
            $_SESSION['message'] = ["type" => "danger", "text" => "Indicator config with name '".$indicator."' already exists."];
            $config = loadDefaultConfig();
            $config = updateConfigValues($config, $_POST);
            $eaIni = convertEaSettingsToTable($config);
            $action = "new";
        }
        else
        {
            $toRun = $_POST["run"] === "1" ? true : false;
            unset($_POST["run"]);

            // new indicator
            $config = loadDefaultConfig();
            $updated = updateConfigValues($config, $_POST);
            $configSaved = saveConfig($indicator, $updated);

            setIndicatorToRun($indicator, $toRun, false);

            if (!$configSaved)
                $_SESSION['message'] = ["type" => "danger", "text" => "Error indicator config '".$indicator."'"];
            else
                $_SESSION['message'] = ["type" => "success", "text" => "New indicator config '".$indicator."' saved!"];

            header("Location: ".$_SERVER["SCRIPT_NAME"]);
            die();
        }
    }
    else if (in_array($action,  ["save-run", "save-dont-run"]))
    {
        $toRun = $action === "save-run";
        $indicators = array_flip(json_decode($indicator, true));
        foreach ($indicatorsList as $key => $value)
        {
            if (!isset($indicators[$value["name"]]))
                continue;

            setIndicatorToRun($value["name"], $toRun, $value["run"]);
        }

        $_SESSION['message'] = ["type" => "success", "text" => "Indicators configs saved to ". ($toRun ? "'run'" : "'don't run'") . "."];

        header("Location: ".$_SERVER["SCRIPT_NAME"]);
        die();
    }
}
else if (isset($_GET["action"]))
{
    $action = $_GET["action"];
    $indicator = isset($_GET["indicator"]) ? base64_decode($_GET["indicator"]) : null;

    if ($action === "edit")
    {
        $indicatorData = getIndicator($indicator, $indicatorsList);

        $config = loadConfig($indicator, $indicatorData["run"]);
        $config["run"] = $indicatorData["run"];
        $eaIni = convertEaSettingsToTable($config);
        $pairs = $config["pairsToTest"];
    }
    else if ($action === "new")
    {
        $config = loadDefaultConfig();
        $eaIni = convertEaSettingsToTable($config);
        $pairs = $config["pairsToTest"];
    }
    else if ($action === "delete")
    {   
        $indicatorData = getIndicator($indicator, $indicatorsList);

        deleteConfig($indicator, $indicatorData["run"]);
        $_SESSION['message'] = ["type" => "success", "text" => "Indicator config '".$indicator."' deleted!"];
        header("Location: ".$_SERVER["SCRIPT_NAME"]);
        die();
    }
    else if ($action === "shutdown")
    {
        shutdownServer();
        die();
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Indicators Configs</title>
    <meta charset="utf-8">
    <link rel="stylesheet" href="assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/dataTables.bootstrap4.min.css">
    <link rel="stylesheet" href="assets/css/select.dataTables.min.css">
    <link rel="stylesheet" href="assets/css/buttons.dataTables.min.css">
    <link rel="stylesheet" href="assets/css/glyphicons.css">
    <style>
        #DataTables_Table_0_info
        {
            float: left !important;
            padding-top: 5px;
        }

        input[type=checkbox].bigger-checkbox
        {
            transform: scale(1.5);
        }

        .navbar-divider
        {
            height: 30px;
            margin: 5px 5px;
            width: 1px;
            background-color: #ccc;
        }
    </style>
</head>
<body>
    <div class="container">
        <div id="notifs">
            <?php if (isset($_SESSION['message'])): 
            $message = $_SESSION['message'];
            unset($_SESSION['message']);
            ?>
            <div class="alert alert-<?php echo $message["type"]; ?> alert-dismissible fade show" role="alert">
                <?php echo $message["text"]; ?>
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <?php endif; ?>
        </div>
        <nav class="navbar navbar-light bg-light navbar-expand-sm">
            <a href="indicatorsConfigs.php" class="navbar-brand">Indicators Configs</a>
            <button class="navbar-toggler" type="button" data-toggle="collapse" data-target="#navbarTogglerDemo02" >
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse justify-content-between" id="navbarTogglerDemo02">
                <ul class="navbar-nav ml-auto">
                    <?php if ($action === "edit"): ?>
                    <li class="nav-item <?php echo $action === 'edit' ? 'active' : ''; ?>">
                        <a class="nav-link <?php echo $action === 'edit' ? 'text-warning' : ''; ?>" href="#"><i class="glyphicon glyphicon-pencil"></i> Edit</a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item <?php echo $action === 'list' ? 'active' : ''; ?>">
                        <a class="nav-link <?php echo $action === 'list' ? 'text-primary' : ''; ?>" href="?"><i class="glyphicon glyphicon-list"></i> List</a>
                    </li>
                    <li class="nav-item <?php echo $action === 'new' ? 'active' : ''; ?>">
                        <a class="nav-link <?php echo $action === 'new' ? 'text-success' : ''; ?>" href="?action=new"><i class="glyphicon glyphicon-plus"></i> New</a>
                    </li>
                    <li class="navbar-divider"></li>
                    <li class="nav-item">
                        <a class="nav-link" href="?action=shutdown"><i class="glyphicon glyphicon-off"></i> Shutdown</a>
                    </li>
                </ul>
            </div>
        </nav>
        <br>
        <div class="row">
            <div class="col-12">
                <div class="tab-content" id="tabContent">
                    <?php if (!in_array($action, ["edit", "new"])): ?>
                    <div class="tab-pane fade <?php echo $action === 'list' ? 'show active' : ''; ?>" id="tab-content-list" role="tabpanel" aria-labelledby="list-tab">
                        <form method="post">
                            <input type="hidden" name="action" value="">
                            <input type="hidden" name="indicator" value="">
                            <h5>List <small class="text-muted">List of saved indicators configs</small></h5>
                            <br>
                            <table id="table-list" class="table table-hover">
                                <thead>
                                    <tr>
                                        <th class="bg-light" style="width: 20px;"></th>
                                        <th class="bg-light" style="width: 10px;">Run</th>
                                        <th class="bg-light">Indicator name</th>
                                        <th class="bg-light" style="width: 100px;">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($indicatorsList as $key => $value): ?>
                                    <tr>
                                        <td class="align-middle text-center">
                                            <div class="form-check">
                                                <input class="form-check-input bigger-checkbox run-checkbox" type="checkbox" id="run-<?php echo $key; ?>" value="1">
                                                <label class="form-check-label" for="run-<?php echo $key; ?>"></label>
                                            </div>
                                        </td>
                                        <td class="align-middle text-center">
                                            <span class="text-<?php echo $value["run"] ? "success glyphicon glyphicon-ok" : "danger glyphicon glyphicon-remove"; ?>" style="font-size: 1.2em;">
                                                <span style="color: transparent;"><?php echo $value["run"] ? "1": "0"; ?></span>
                                            </span>
                                        </td>
                                        <td>
                                            <?php echo $value["name"]; ?>
                                        </td>
                                        <td class="align-middle text-center">
                                            <a class="btn btn-warning btn-sm" href="#" onclick="window.location='?action=edit&indicator=<?php echo base64_encode($value["name"]); ?>'">
                                                Edit
                                            </a>
                                            <a class="btn btn-danger btn-sm" href="#" onclick="return deleteIndicator('<?php echo base64_encode($value["name"]); ?>')">
                                                Delete
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </form>
                    </div>
                    <?php else: ?>
                    <div class="tab-pane fade <?php echo in_array($action, ["edit", "new"]) ? 'show active' : ''; ?>" id="tab-content-<?php echo $action; ?>" role="tabpanel" aria-labelledby="edit-tab">
                        <form method="post" <?php echo ($action === "new") ? 'onsubmit="return checkIndicatorName()"' : ''; ?>>
                            <input type="hidden" name="action" value="save-<?php echo $action; ?>">
                            <span class="h5">
                                <?php echo ucfirst($action); ?> 
                                <?php if(isset($_GET["indicator"])): die();?>
                                <input type="hidden" name="indicator" value="<?php echo $_GET['indicator']; ?>">
                                <small class="text-muted"><?php echo base64_decode($_GET["indicator"]); ?></small>
                                <?php else: ?>
                                <span >
                                    <input style="width:300px"  type="text" class="form-control form-control-sm d-inline-block" 
                                        name="indicator" placeholder="Indicator name" value="<?php echo htmlentities($indicator); ?>" required>
                                </span>
                                <?php endif;?>
                                &nbsp;
                                <div class="form-check form-check-inline h6">
                                    <input class="form-check-input" type="checkbox" <?php echo $action === "edit" ? ($config["run"] ? "checked" : "") : ""; ?> id="run-<?php echo $action; ?>" name="run" value="1">
                                    <label class="form-check-label" for="run-<?php echo $action; ?>">Run</label>
                                </div>
                                <span class="float-right">
                                    <input type="submit" class="btn btn-success" onclick="window.onbeforeunload=null;" value="Save">
                                </span>
                            </span>
                            <br><br>

                            <ul class="nav nav-tabs">
                                <li class="nav-item">
                                    <a class="nav-link active" href="#edit-ea" data-toggle="tab" role="tab">EA Settings</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" href="#edit-pairs" data-toggle="tab" role="tab">Pairs to test</a>
                                </li>
                            </ul>
                            <div class="tab-content" id="myTabContent">
                                <div class="tab-pane fade show active" id="edit-ea" role="tabpanel">
                                    <br>
                                    <table class="table table-sm table-hover">
                                        <thead>
                                            <tr>
                                                <th class="bg-light">Name</th>
                                                <th>Opt.</th>
                                                <th>Variable</th>
                                                <th>Start</th>
                                                <th>Step</th>
                                                <th>Stop</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($eaIni as $key => $value): 
                                            $alone = !isset($value["start"]) && !isset($value["step"]) && !isset($value["stop"]);
                                            ?>
                                            <tr>
                                                <td class="bg-light align-middle">
                                                    <?php echo $value["variable"]["name"]; ?>
                                                </td>
                                                <td class="align-middle text-center">
                                                    <?php if (isset($value["optimization"]["value"])): ?>
                                                    <div class="form-check">
                                                        <input class="form-check-input bigger-checkbox" type="checkbox" id="defaultCheck-<?php echo $key; ?>" 
                                                            <?php echo $value["optimization"]["value"] === '1' ? 'checked' : '' ; ?> 
                                                            name="eaIni[inputs][<?php echo htmlentities($value["optimization"]["name"]); ?>]" value="1">
                                                        <label class="form-check-label" for="defaultCheck-<?php echo $key; ?>"></label>
                                                    </div>
                                                    <?php endif; ?>
                                                </td>
                                                <td colspan="<?php echo $alone ? '4' :''; ?>">
                                                    <input class="form-control" type="text"
                                                        value="<?php echo htmlentities($value["variable"]["value"]); ?>" 
                                                        name="eaIni[inputs][<?php echo htmlentities($value["variable"]["name"]); ?>]">
                                                </td>
                                                <?php if (!$alone): ?>
                                                <td>
                                                    <input class="form-control" type="text"
                                                        value="<?php echo htmlentities($value["start"]["value"]); ?>" 
                                                        name="eaIni[inputs][<?php echo htmlentities($value["start"]["name"]); ?>]"
                                                        <?php echo $value["optimization"]["value"] === '1' ? '' : 'disabled'; ?>>
                                                </td>
                                                <td>
                                                    <input class="form-control" type="text"
                                                        value="<?php echo htmlentities($value["step"]["value"]); ?>" 
                                                        name="eaIni[inputs][<?php echo htmlentities($value["step"]["name"]); ?>]"
                                                        <?php echo $value["optimization"]["value"] === '1' ? '' : 'disabled'; ?>>
                                                </td>
                                                <td>
                                                    <input class="form-control" type="text"
                                                        value="<?php echo htmlentities($value["stop"]["value"]); ?>" 
                                                        name="eaIni[inputs][<?php echo htmlentities($value["stop"]["name"]); ?>]"
                                                        <?php echo $value["optimization"]["value"] === '1' ? '' : 'disabled'; ?>>
                                                </td>
                                                <?php endif; ?>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="tab-pane fade" id="edit-pairs" role="tabpanel">
                                    <br>
                                    <div class="btn-group-toggle" data-toggle="buttons">
                                    <?php foreach ($pairs as $key => $value): ?>
                                        <label class="btn btn-outline-info <?php echo $value ? 'active' :'' ;?>" style="width:100px; margin-bottom: 10px; margin-right: 10px; ">
                                            <input type="checkbox" <?php echo $value ? 'checked' :'' ;?> autocomplete="off" name="pairsToTest[<?php echo $key; ?>]" value="1"> <?php echo $key; ?>
                                        </label>
                                    <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <script src="assets/js/jquery-3.3.1.min.js"></script>
    <script src="assets/js/bootstrap.min.js"></script>
    <script src="assets/js/datatables.min.js"></script>
    <script src="assets/js/jquery.dataTables.min.js"></script>
    <script src="assets/js/dataTables.select.min.js"></script>
    <script src="assets/js/dataTables.buttons.min.js"></script>
    <script>
        function buttonSaveRun(run)
        {
            var data = $("#table-list").DataTable().rows({selected: true}).data();
            if (data.length === 0)
            {
                alert("No rows selected!");
                return;
            }

            if (confirm("Set "+data.length+" rows to "+(run ? "" : "not ")+"run?"))
            {
                var indicators = [];
                data.each(function(el, i)
                {
                    indicators.push(el[2]);
                });

                $("[name=indicator").val(JSON.stringify(indicators));
                $("[name=action").val(run ? "save-run" : "save-dont-run");
                //window.location = "?action=save-run&indicator="+encodeURIComponent(indi);
                $("#table-list").parents("form").submit();
                return true;
            }
            return false;
        }

        var table = $("#table-list").DataTable({
            "paging": false,
            "select": 
            {
                items: "row",
                style: "multi",
                selector: '.run-checkbox',
            },
            "buttons": 
            [
                {
                    text: "Save 'run'",
                    action: buttonSaveRun.bind(null, true),
                },
                {
                    text: "Save 'don't run'",
                    action: buttonSaveRun.bind(null, false),
                },
            ],
            "dom": "Blfrtpi",
        });

        /*
        <?php foreach ($indicatorsList as $key => $value): ?>
        <?php if ($value["run"]): ?>
            table.row(<?php echo $key; ?>).select();
        <?php endif; ?>
        <?php endforeach; ?>
        */

        $(".bigger-checkbox").on("click", function(e)
        {
            var checked = e.currentTarget.checked;
            var tr = $(e.currentTarget).parents("tr");
            tr.find("td").eq(3).find("input[type=text]").prop("disabled", !checked);
            tr.find("td").eq(4).find("input[type=text]").prop("disabled", !checked);
            tr.find("td").eq(5).find("input[type=text]").prop("disabled", !checked);
        });

        <?php if ($action !== "list"): ?>
        window.onbeforeunload = function()
        {
            return "Leaving will discard any changes! Continue?";
        };
        <?php endif; ?>

        function deleteIndicator(indi)
        {
            if (confirm("Are you sure you want to delete '"+atob(indi)+"' config?"))
            {
                window.location = "?action=delete&indicator="+encodeURIComponent(indi);
                return true;
            }
            return false;
        }

        function checkIndicatorName()
        {
            if ($("[name=indicator]").val().trim() === "")
            {
                alert("New indicator name is empty!");
                return false;
            }

            return true;
        }
    </script>
</body>
</html>