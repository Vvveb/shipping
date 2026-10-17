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

/*
Name: Default Shipping
Slug: shipping
Category: shipping
Url: https://www.vvveb.com
Description: Adds shipping methods for checkout page
Author: givanz
Version: 0.1
Thumb: shipping.svg
Author url: https://www.vvveb.com
Settings: /admin/index.php?module=plugins/shipping/settings
*/

use function Vvveb\getSetting;

use Vvveb\Plugins\Shipping\Shipping as ShippingMethod;

use function Vvveb\slugify;

use Vvveb\System\Core\Request;
use Vvveb\System\Event;
use Vvveb\System\Locale;
use Vvveb\System\Shipping as ShippingApi;

if (!defined('V_VERSION')) {
	exit('Invalid request!');
}

class Shipping {
	private $namespace = 'shipping';

	public function init(): void {
		$settings = getSetting($this->namespace, null, []);
		$shipping = ShippingApi::getInstance();

		foreach ($settings as $method) {
			$lang = $method['lang'][Locale::getLanguageId()] ?? [];

			if ($lang) {
				unset($method['lang']);
				$method += $lang;
			}

			$name = slugify($lang['title'] ?? $this->namespace);
			$shipping->registerMethod($name, ShippingMethod::class, $method);
		}
	}

	public function admin(): void {
		$request = Request::getInstance();
		$module  = $request->get['module'] ?? '';

		if ($module == 'order/order' || $module == 'settings/site') {
			$this->init();
		}
	}

	public function app(): void {
		//load only on cart and checkout page for performance
		Event::on('Vvveb\Controller\Base', 'init', __CLASS__, function($site) {
			$request = Request::getInstance();
			$route   = $request->get['route'] ?? '';
			$module  = $request->get['module'] ?? '';

			if ($route == '/checkout' || $module == 'checkout/checkout' || $module == 'checkout/checkout/index'
				|| $module == 'cart' || $module == 'cart/cart' || $module == 'cart/cart/index') {
				$this->init();
			}

			return [$site];
		});
	}

	public function __construct() {
		if (\Vvveb\isEditor()) {
			return;
		}

		if (APP == 'admin') {
			$this->admin();
		} elseif (APP == 'app') {
			$this->app();
		}
	}
}

$shipping = new Shipping();
