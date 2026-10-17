<?php

/**
 * Vvveb
 *
 * Copyright (C) 2023  Ziadin Givan
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace Vvveb\Plugins\Shipping\Controller;

use function Vvveb\__;

use Vvveb\Controller\Base;
use Vvveb\Controller\Content\AutocompleteTrait;

use function Vvveb\deleteSetting;
use function Vvveb\getSetting;
use function Vvveb\setMultiSetting;

use Vvveb\Sql\Region_GroupSQL;
use Vvveb\Sql\Shipping_StatusSQL;
use Vvveb\Sql\Tax_TypeSQL;
use Vvveb\System\CacheManager;

class Settings extends Base {
	use AutocompleteTrait;

	private $namespace = 'shipping';

	protected string $object = 'product';

	public function save() {
		$settings  = $this->request->post['methods'] ?? false;

		if ($settings) {
			deleteSetting($this->namespace, null, $this->global['site_id']);
			$settings = array_values($settings); //reset index
			$results  = setMultiSetting($this->namespace, $settings, $this->global['site_id']);

			CacheManager::delete();
			$this->view->success[] = __('Settings saved!');
		} else {
			$this->view->errors = $errors;
		}

		return $this->index();
	}

	public function index(): void {
		$geoRegion = new Region_GroupSQL();
		$regions	  = $geoRegion->getAll($this->global);

		$region_group_id = [];

		if (isset($regions['region_group'])) {
			foreach ($regions['region_group'] as $region_group) {
				$region_group_id[$region_group['region_group_id']] = $region_group['name'];
			}
		}

		$this->view->region_group = $region_group_id;

		$taxTypes = new Tax_TypeSQL();
		$taxes    = $taxTypes->getAll($this->global);

		$tax_type_id = [];

		if (isset($taxes['tax_type'])) {
			foreach ($taxes['tax_type'] as $tax) {
				$tax_type_id[$tax['tax_type_id']] = $tax['name'];
			}
		}

		$shippingStatus = new Shipping_StatusSQL();
		$statuses       = $shippingStatus->getAll($this->global);

		$shipping_status_id = [];
		if (isset($statuses['shipping_status'])) {
			foreach ($statuses['shipping_status'] as $status) {
				$shipping_status_id[$status['shipping_status_id']] = $status['name'];
			}
		}

		$this->view->methods          = getSetting($this->namespace, null, null, $this->global['site_id']);
		$this->view->tax_type         = $tax_type_id;
		$this->view->shipping_status  = $shipping_status_id;
	}
}
