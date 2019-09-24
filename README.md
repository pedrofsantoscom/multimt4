# Multi Mt4
Manages multiple MetaTrader 4 copies to run MT4 backtester tests at the same time, via EA settings for each indicator.
![](https://github.com/pedrofsantoscom/multi-mt4/raw/master/running.png)

## Requirements
- Windows 10
- MetaTrader 4
- PHP 7.0+
  - [Download Thread Safe Zip](https://windows.php.net/download)
  - [Add php to PATH](https://john-dugan.com/add-php-windows-path-variable/)
  - Inside php installation folder, rename `php.ini-development` to `php.ini`, inside the file find these and then save:
    - `extension=mbstring`: delete the `;` from the beginning of the line
    - `extension=fileinfo`: delete the `;` from the beginning of the line
    - `extension=com_dotnet`: delete the `;` from the beginning of the line OR add a new line with `extension=com_dotnet`

## Running

- Run `run-multiMt4.bat` to create the `config.json` file
- Open `config.json` file and:
  - `eaIniFile`: the `ea_name.ini` file thats under `"mt4DataFolder"\tester\`
  - `backTesterIni`: mt4login, mt4password, brokerserver, mt4 backtester test from date, mt4 backtester test to date
    ```json
    {
        "Login": "mt4login",
        "Password": "mt4password",
        "Server": "broker_server",
        "TestFromDate": "2014.06.01",
        "TestToDate": "2019.06.01"
    }
    ```
  - `workersLimit`: how many workers you want running at the same time
  - `"mt4Paths"`: add all your MetaTrader 4 copies/installations paths, e.g.:
    ```json
    "mt4Paths": 
    [
      "C:\\Program Files (x86)\\MetaTrader2\\", 
      "C:\\Program Files (x86)\\MetaTrader3\\"
    ],
    ```
  - 
- Run `run-indicatorsConfigs.bat`. A commandline window will show up and that is running PHP built in webserver, don't close it.
- A browser window will show up a web page where you will use it to create the EA settings file for an indicator.
![](https://github.com/pedrofsantoscom/multi-mt4/raw/master/indicatorsConfigs.png)
- The default EA settings (on `/indicators-configs/default` file) are from [goncaloe/nnfx-backtest backtest EA](https://github.com/goncaloe/nnfx-backtest). Change it if you are using a diferent EA.
- New/Edit page shows the EA settings just like in MetaTrader4 but with an adicional tab for what currency pairs you would like to test the config on.
- Setting the `Run` checkbox will make that config run when you run MultiMt4 program. You can also change the `Run` state on the `List` page.
- Next is to open the `run-multiMt4.bat` and watch all indicators configs being run on all currency pairs selected within it.
- Sit back and watch all the tests being run automatically.
- When the tests are done, the MetaTrader 4 html results will be converted to csv and all those files will be under `\results\` folder.
