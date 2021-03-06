<?php
/**
 * AvailabilityMapController.php
 *
 * -Description-
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package    LibreNMS
 * @link       http://librenms.org
 * @copyright  2018 Tony Murray
 * @author     Tony Murray <murraytony@gmail.com>
 */

namespace App\Http\Controllers\Widgets;

use App\Models\Device;
use App\Models\DeviceGroup;
use App\Models\Service;
use App\Models\UserWidget;
use Illuminate\Http\Request;
use LibreNMS\Config;

class AvailabilityMapController extends WidgetController
{
    protected $title = 'Availability Map';

    public function __construct()
    {
        $this->defaults = [
            'title' => null,
            'type' => (int)Config::get('webui.availability_map_compact', 0),
            'tile_size' => 12,
            'color_only_select' => 0,
            'show_disabled_and_ignored' => 0,
            'mode_select' => 0,
            'device_group' => 0,
        ];
    }

    public function getView(Request $request)
    {
        $data = $this->getSettings();

        $devices = [];
        $device_totals = [];
        $services = [];
        $services_totals = [];

        $mode = $data['mode_select'];
        if ($mode == 0 || $mode == 2) {
            list($devices, $device_totals) = $this->getDevices($request);
        }
        if ($mode > 0) {
            list($services, $services_totals) = $this->getServices($request);
        }

        $data['device'] = Device::first();

        $data['devices'] = $devices;
        $data['device_totals'] = $device_totals;
        $data['services'] = $services;
        $data['services_totals'] = $services_totals;

        return view('widgets.availability-map', $data);
    }



    public function getSettingsView(Request $request)
    {
        $settings = $this->getSettings();
        $settings['device_group'] = DeviceGroup::find($settings['device_group']);

        return view('widgets.settings.availability-map', $settings);
    }

    /**
     * @param Request $request
     * @return array
     */
    private function getDevices(Request $request)
    {
        $settings = $this->getSettings();

        // filter for by device group or show all
        if ($group_id = $settings['device_group']) {
            $device_query = DeviceGroup::find($group_id)->devices()->hasAccess($request->user());
        } else {
            $device_query = Device::hasAccess($request->user());
        }

        if (!$settings['show_disabled_and_ignored']) {
            $device_query->isActive();
        }
        $devices = $device_query->select('devices.device_id', 'hostname', 'sysName', 'status', 'uptime', 'disabled', 'ignore')->get();

        // process status
        $uptime_warn = Config::get('uptime_warning', 84600);
        $totals = ['warn' => 0, 'up' => 0, 'down' => 0, 'ignored' => 0, 'disabled' => 0];
        foreach ($devices as $device) {
            if ($device->disabled) {
                $totals['disabled']++;
                $device->stateName = "disabled";
                $device->labelClass = "blackbg";
            } elseif ($device->ignore) {
                $totals['ignored']++;
                $device->stateName = "ignored";
                $device->labelClass = "label-default";
            } elseif ($device->status == 1) {
                if (($device->uptime < $uptime_warn) && ($device->uptime != 0)) {
                    $totals['warn']++;
                    $device->stateName = 'warn';
                    $device->labelClass = 'label-warning';
                } else {
                    $totals['up']++;
                    $device->stateName = 'up';
                    $device->labelClass = 'label-success';
                }
            } else {
                $totals['down']++;
                $device->stateName = 'down';
                $device->labelClass = 'label-danger';
            }
        }
        return [$devices, $totals];
    }

    private function getServices($request)
    {
        $settings = $this->getSettings();

        // filter for by device group or show all
        if ($group_id = $settings['device_group']) {
            $services_query = DeviceGroup::find($group_id)->services()->hasAccess($request->user());
        } else {
            $services_query = Service::hasAccess($request->user());
        }

        $services = $services_query->with(['device' => function ($query) {
            $query->select('devices.device_id', 'hostname', 'sysName');
        }])->select('service_id', 'device_id', 'service_type', 'service_desc', 'service_status')->get();

        // process status
        $totals = ['warn' => 0, 'up' => 0, 'down' => 0];
        foreach ($services as $service) {
            if ($service->service_status == 0) {
                $service->labelClass = "label-success";
                $service->stateName = "up";
                $totals['up']++;
            } elseif ($service->service_status == 1) {
                $service->labelClass = "label-warning";
                $service->stateName = "warn";
                $totals['warn']++;
            } else {
                $service->labelClass = "label-danger";
                $service->stateName = "down";
                $totals['down']++;
            }
        }
        return [$services, $totals];
    }
}
