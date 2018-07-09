<?php
namespace DcaDiluter;

class ProfitTrailer
{
    public function __construct($config)
    {
        $this->config = $config;
    }

    public function getAllLogs()
    {
        $dataUrl =  $dcaUrl = $this->config->serverUrl . '/api/data' . '?token=' . $this->config->token;
        return json_decode(file_get_contents($dataUrl));
    }

    public function setBoughtCost($market, $cost)
    {
        $dataUrl = $this->config->serverUrl . '/settingsapi/data' . '?token=' . $this->config->token;
        return json_decode(file_get_contents($dataUrl));
    }

    public function resetStoredAverage($market, $averageCost)
    {
        $shortMarket = substr($market, 0, -3);
        $saveData = "{$shortMarket}_reset_stored_average = true\n{$shortMarket}_bought_price = $averageCost";

        $url = $this->config->serverUrl . '/settingsapi/settings/save' . '?license=' . $this->config->license;
        $url .= '&fileName=HOTCONFIG&configName=North_Star_1.02';
        $url .= '&saveData=' . urlencode($saveData);


        $response = \Httpful\Request::post($url)->send();
    }

    public function setDcaLevels($market, $dcaLevels)
    {
        $shortMarket = substr($market, 0, -3);
        $saveData = "{$shortMarket}_DCA_set_buy_times = $dcaLevels";

        $url = $this->config->serverUrl . '/settingsapi/settings/save' . '?license=' . $this->config->license;
        $url .= '&fileName=HOTCONFIG&configName=North_Star_1.02';
        $url .= '&saveData=' . urlencode($saveData);

        $response = \Httpful\Request::post($url)->send();
    }

}
