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

namespace Vvveb\Plugins\Shipping;

use function Vvveb\__;

use Vvveb\Sql\Region_groupSQL;
use Vvveb\System\Cart\Currency;
use Vvveb\System\Core\View;
use Vvveb\System\Locale;
use Vvveb\System\ShippingMethod;

if (!defined('V_VERSION')) {
	exit('Invalid request!');
}

class Shipping extends ShippingMethod {
	private $namespace = 'shipping';

	/**
	 * @var array<string,string> legacy/unaliased condition identifiers mapped to canonical ones
	 */
	private const CONDITION_ALIASES = [
		'taxonomy_item_id'       => 'product_category',
		'product_field_value_id' => 'product_field_value',
	];

	/**
	 * @var array<int,array<string,mixed>>|null cart products with full data loaded from DB
	 */
	private ?array $products_data = null;

	/**
	 * @var array<string,array> cached taxonomy item ids per product id and type
	 */
	private array $taxonomy_cache = [];

	/**
	 * @var array<string,mixed> Checkout context (address, country, region...).
	 */
	private array $checkoutInfo = [];

	protected $method_data = [
		'name'            => 'shipping',
		'title'           => 'Shipping',
		'description'     => 'Default shipping',
		'region_id'       => 0,
		'cost'            => 0,
		'tax_type_id'     => 0,
		'tax_included'    => 0,
		'region_group_id' => 0,
		'free_shipping'   => 0,
		'difference'      => 0,
		'text'            => '',
		'free_message'    => 'Add more products worth at least %s to get free shipping!',
	];

	private function getCost(): void {
		$subtotal = $this->cart->getSubTotal(); //without totals/taxes
		$total    = $this->cart->getGrandTotal();

		if (($this->method_data['cost'] == 0)
			|| (($this->method_data['free_shipping'] > 0) && ($total > $this->method_data['free_shipping']))) {
			$this->method_data['cost']  = 0;
		}

		if (isset($this->method_data['weight'])) {
			$cartWeight       = $this->cart->getWeight();
			$base             = (float)($this->method_data['base-weight'] ?? 0);
			$additionalPrice  = 0;
			$additionalWeight = $cartWeight - $base;

			if ($additionalWeight > 0) {
				//get heaviest first
				$steps = $this->method_data['weight'];
				//sort expensive first
				usort($steps, fn ($a, $b) => -($a['price'] <=> $b['price']));

				foreach ($steps as $step) {
					if ($this->evaluateConditions($step)) {
						$this->method_data['cost'] = $step['price'];

						break;
					}
				}
			}
		}

		if ($this->method_data['cost'] == 0) {
			$this->method_data['text']  = __('Free shipping');
		}

		$this->method_data['difference'] = max($this->method_data['free_shipping'] - $total - $this->method_data['cost'], 0);
	}

	/**
	 * Evaluate all condition groups for a pricing step.
	 *
	 * Groups are OR'd together; rules within a group are AND'd.
	 * A step with no conditions always matches.
	 *
	 * @param array<string, mixed> $step the pricing step with 'conditions'
	 *
	 * @return bool true if any group passes (or no conditions exist)
	 */
	protected function evaluateConditions(array $step): bool {
		$conditions = $step['conditions'] ?? [];

		if (!$conditions) {
			return true;
		}

		// groups are OR'd
		foreach ($conditions as $group) {
			if (!is_array($group) || !$group) {
				continue;
			}

			$groupPass = true;

			// rules within a group are AND'd
			foreach ($group as $rule) {
				if (!is_array($rule)) {
					continue;
				}

				$condition = $this->normalizeCondition((string)($rule['condition'] ?? 'none'));
				$operator  = $rule['operator'] ?? '==';
				$value     = $rule['value'] ?? '';

				// 'none' / 'always' condition always passes
				if ($condition === 'none' || $condition === 'always') {
					continue;
				}

				// Match-type conditions check if any cart product has the given tag/category/class
				if ($this->isMatchCondition($condition)) {
					if (!$this->evaluateMatchCondition($condition, (string)$operator, $value)) {
						$groupPass = false;

						break;
					}

					continue;
				}

				$actual = $this->getConditionValue($condition);

				// coupon checks membership of the given code in applied coupons
				if ($condition === 'coupon') {
					$codes  = array_map('trim', explode(',', (string)$actual));
					$passes = in_array(trim((string)$value), $codes, true);

					if (!($operator === '!=' ? !$passes : $passes)) {
						$groupPass = false;

						break;
					}

					continue;
				}

				if (!$this->compare($actual, $operator, $value)) {
					$groupPass = false;

					break;
				}
			}

			if ($groupPass) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a condition is a "match" type that checks product attributes
	 * (tags, categories, shipping class, field values) rather than numeric comparison.
	 *
	 * @param string $condition the condition type identifier
	 *
	 * @return bool true if this is a match-type condition
	 */
	protected function isMatchCondition(string $condition): bool {
		return in_array($condition, [
			'product', 'product_tag', 'product_category', 'manufacturer',
			'shipping_class', 'product_field_value', 'product_field_range',
		], true);
	}

	/**
	 * Normalize a condition identifier to its canonical form.
	 *
	 * Accepts prefixed identifiers used by the admin UI (cart.total, product.price,
	 * user.user_group_id, checkout.country_id ...) and legacy unprefixed ones.
	 *
	 * @param string $condition raw condition identifier
	 *
	 * @return string canonical condition identifier
	 */
	protected function normalizeCondition(string $condition): string {
		$condition = trim($condition);

		$pos = strrpos($condition, '.');

		if ($pos !== false) {
			$condition = substr($condition, $pos + 1);
		}

		return self::CONDITION_ALIASES[$condition] ?? $condition;
	}

	/**
	 * Evaluate a match-type condition against cart products.
	 *
	 * @param string $condition the condition type
	 * @param string $operator  the operator (== means "contains", != means "does not contain")
	 * @param mixed  $value     The value to match (tag ID, category ID, etc.).
	 *
	 * @return bool true if the match condition passes
	 */
	protected function evaluateMatchCondition(string $condition, string $operator, mixed $value): bool {
		$matches = false;

		foreach ($this->getCartProductsData() as $product) {
			$productId = (int)($product['product_id'] ?? 0);

			switch ($condition) {
				case 'product':
					// specific product present in cart
					$matches = ($productId === (int)$value);

					break;

				case 'product_tag':
				case 'product_category':
					$type    = ($condition === 'product_tag') ? 'tags' : 'categories';
					$items   = $this->getProductTaxonomyItemIds($productId, $type);
					$matches = in_array((string)$value, array_map('strval', $items), true);

					break;

				case 'manufacturer':
					$productVal = $product['manufacturer_id'] ?? 0;
					$matches    = ((int)$productVal === (int)$value);

					break;

				case 'shipping_class':
					$productVal = $product['shipping_class'] ?? $product['shipping_class_id'] ?? null;

					if ($productVal !== null && $productVal !== '') {
						$matches = ((string)$productVal === (string)$value);
					}

					break;

				case 'product_field_value':
					// value format: "field_name:expected_value"
					if (str_contains((string)$value, ':')) {
						[$fieldName, $expectedVal] = explode(':', (string)$value, 2);
						$productVal                = $product[$fieldName] ?? null;

						if ($productVal !== null) {
							$matches = ((string)$productVal === (string)$expectedVal);
						}
					}

					break;

				case 'product_field_range':
					// value format: "field_name:min-max"
					if (str_contains((string)$value, ':')) {
						[$fieldName, $range] = explode(':', (string)$value, 2);
						$productVal          = (float)($product[$fieldName] ?? 0);

						if (str_contains($range, '-')) {
							[$min, $max] = explode('-', $range, 2);
							$matches     = ($productVal >= (float)$min && $productVal <= (float)$max);
						} else {
							$matches = ($productVal >= (float)$range);
						}
					}

					break;
			}

			if ($matches) {
				break;
			}
		}

		return $operator === '!=' ? !$matches : $matches;
	}

	/**
	 * Compare two values using the given operator.
	 *
	 * @param mixed  $actual   the actual value from the cart/checkout
	 * @param string $operator one of ==, !=, >, <, >=, <=
	 * @param mixed  $expected the expected value from the rule
	 *
	 * @return bool true if the comparison passes
	 */
	protected function compare(mixed $actual, string $operator, mixed $expected): bool {
		// non numeric values (post codes, coupon codes ...) compare as strings
		if (!is_numeric($actual) || !is_numeric($expected)) {
			return match ($operator) {
				'==', '=' => (string)$actual === (string)$expected,
				'!='      => (string)$actual !== (string)$expected,
				'>'       => (string)$actual > (string)$expected,
				'<'       => (string)$actual < (string)$expected,
				'>='      => (string)$actual >= (string)$expected,
				'<='      => (string)$actual <= (string)$expected,
				default   => false,
			};
		}

		$actual   = (float)$actual;
		$expected = (float)$expected;

		return match ($operator) {
			'=='    => $actual == $expected,
			'!='    => $actual != $expected,
			'>'     => $actual > $expected,
			'<'     => $actual < $expected,
			'>='    => $actual >= $expected,
			'<='    => $actual <= $expected,
			default => false,
		};
	}

	/**
	 * Resolve the actual value for a given condition type.
	 *
	 * For per-product conditions the value is aggregated across all cart
	 * products (max for dimensions, sum for totals, any-match for tags etc.).
	 *
	 * @param string $condition canonical condition identifier (see normalizeCondition())
	 *
	 * @return float|string the resolved value (0 / '' if not applicable)
	 */
	protected function getConditionValue(string $condition): float|string {
		$condition = $this->normalizeCondition($condition);

		return match ($condition) {
			// Cart-level conditions
			'total'    => (float)$this->cart->getGrandTotal(),
			'subtotal' => (float)$this->cart->getSubTotal(),
			'weight'   => (float)$this->cart->getWeight(),

			// Cart max dimensions (largest single product dimension across cart)
			'max_width'     => $this->cartMaxDimension('width'),
			'max_height'    => $this->cartMaxDimension('height'),
			'max_length'    => $this->cartMaxDimension('length'),
			'max_dimension' => $this->cartMaxDimension(),

			// Per-product conditions (aggregated)
			'price'          => $this->cartProductSum('price'),
			'product_weight' => (float)$this->cart->getWeight(),
			'height'         => $this->cartMaxDimension('height'),
			'width'          => $this->cartMaxDimension('width'),
			'length'         => $this->cartMaxDimension('length'),
			'volume'         => $this->cartVolume(),
			'stock_quantity' => array_sum(array_column($this->getCartProductsData(), 'stock_quantity')),
			'stock_status'   => $this->cartMinStockStatus(),

			// Checkout / address conditions
			'country_id' => (float)($this->checkoutInfo['shipping_country_id'] ?? $this->checkoutInfo['country_id'] ?? 0),
			'region_id'  => (float)($this->checkoutInfo['shipping_region_id'] ?? $this->checkoutInfo['region_id'] ?? 0),
			'post_code'  => (string)($this->checkoutInfo['shipping_post_code'] ?? $this->checkoutInfo['shipping_address']['post_code'] ?? ''),
			'coupon'     => implode(', ', array_keys($this->cart->getCoupons())),

			// User
			'user_group_id' => (float)(\Vvveb\System\User\User::current()['user_group_id'] ?? 0),

			// Time
			'time_of_the_day' => (float)date('G'), // 0-23
			'day_of_the_week' => (float)date('N'), // 1 (Mon) - 7 (Sun)

			// Shipping
			'shipping_cost' => (float)($this->method_data['cost'] ?? 0),

			default => 0,
		};
	}

	/**
	 * Get the maximum value of a dimension across all cart products.
	 *
	 * @param string|null $field The dimension field ('width', 'height', 'length').
	 *                           Null to return the largest of all three.
	 *
	 * @return float the maximum dimension value
	 */
	protected function cartMaxDimension(?string $field = null): float {
		$max    = 0;
		$fields = $field ? [$field] : ['width', 'height', 'length'];

		foreach ($this->getCartProductsData() as $product) {
			foreach ($fields as $f) {
				$val = (float)($product[$f] ?? 0);

				if ($val > $max) {
					$max = $val;
				}
			}
		}

		return $max;
	}

	/**
	 * Sum a field across all cart products (price * quantity for price).
	 *
	 * @param string $field the product field to sum
	 *
	 * @return float the summed value
	 */
	protected function cartProductSum(string $field): float {
		$sum = 0;

		foreach ($this->cart->getAll() as $product) {
			$quantity = (float)($product['quantity'] ?? 1);
			$val      = (float)($product[$field] ?? 0);

			if ($field === 'price' || $field === 'stock_quantity') {
				$sum += $val * $quantity;
			} else {
				$sum += $val;
			}
		}

		return $sum;
	}

	/**
	 * Calculate total volume across all cart products.
	 *
	 * @return float the total volume
	 */
	protected function cartVolume(): float {
		$volume = 0;
		$data   = $this->getCartProductsData();

		foreach ($this->cart->getAll() as $row) {
			$product = $data[(int)($row['product_id'] ?? 0)] ?? null;

			if (!$product) {
				continue;
			}

			$w = (float)($product['width'] ?? 0);
			$h = (float)($product['height'] ?? 0);
			$l = (float)($product['length'] ?? 0);
			$q = (float)($row['quantity'] ?? 1);

			$volume += ($w * $h * $l) * $q;
		}

		return $volume;
	}

	/**
	 * Get cart products with full data loaded from DB, keyed by product id.
	 *
	 * Cart items only carry price/weight; conditions that need dimensions,
	 * taxonomy, manufacturer ... load full product data lazily and cache it.
	 *
	 * @return array<int, array<string, mixed>> products keyed by product id
	 */
	protected function getCartProductsData(): array {
		if ($this->products_data !== null) {
			return $this->products_data;
		}

		$this->products_data = [];

		$productIds = [];

		foreach ($this->cart->getAll() as $product) {
			$productId = (int)($product['product_id'] ?? 0);

			if ($productId) {
				$productIds[$productId] = $productId;
			}
		}

		if ($productIds) {
			try {
				$products = new \Vvveb\Sql\ProductSQL();
				$results  = $products->getAll(['product_id' => array_values($productIds), 'site_id' => SITE_ID]);

				foreach (($results['product'] ?? []) as $product) {
					$this->products_data[(int)$product['product_id']] = $product;
				}
			} catch (\Throwable $e) {
				// db not available or table missing, treat as no product data
				$this->products_data = [];
			}
		}

		return $this->products_data;
	}

	/**
	 * Get taxonomy item ids (categories or tags) for a cart product.
	 *
	 * @param int    $productId product id
	 * @param string $type      taxonomy type ('categories' or 'tags')
	 *
	 * @return array<int, int|string> taxonomy item ids
	 */
	protected function getProductTaxonomyItemIds(int $productId, string $type): array {
		$key = $type . ':' . $productId;

		if (isset($this->taxonomy_cache[$key])) {
			return $this->taxonomy_cache[$key];
		}

		$items = [];

		try {
			$categories = new \Vvveb\Sql\CategorySQL();
			$results    = $categories->getCategories([
				'product_id'  => $productId,
				'type'        => $type,
				'language_id' => Locale::getLanguageId(),
				'site_id'     => SITE_ID,
			]);

			foreach (($results['categories'] ?? []) as $item) {
				$items[] = $item['taxonomy_item_id'];
			}
		} catch (\Throwable $e) {
			$items = [];
		}

		$this->taxonomy_cache[$key] = $items;

		return $items;
	}

	/**
	 * Lowest stock status across cart products (lower means more available).
	 *
	 * @return float minimum stock status id
	 */
	private function cartMinStockStatus(): float {
		$statuses = array_column($this->getCartProductsData(), 'stock_status_id');

		return $statuses ? (float)min($statuses) : 0.0;
	}

	public function getMethod(array &$checkoutInfo = [], array $options = []): array|false {
		$this->method_data         = $options + $this->method_data;
		$this->method_data['cost'] = (float)($this->method_data['cost']);
		$this->checkoutInfo        = &$checkoutInfo;

		//if set only for specific region(s) check if address matches region
		$region_group_id     = $this->method_data['region_group_id'];
		$shipping_country_id = $checkoutInfo['shipping_country_id'] ?? false;
		$shipping_region_id  = $checkoutInfo['shipping_region_id'] ?? 0;

		if ($region_group_id != 0) {
			$region  = new Region_groupSQL();
			$regions = [];
			$params  = ['region_group_id' => (int)$region_group_id, 'country_id' => (int)$shipping_country_id, 'region_id' => (int)$shipping_region_id];
			$regions = $region->isRegion($params);

			if (isset($regions['count']) && $regions['count'] == 0) {
				return [];
			}
		}

		$this->getCost();

		return $this->method_data;
	}

	public function init(): void {
		//remove previous total and tax
		$this->cart->removeTotal($this->namespace);
		$this->cart->removeTax($this->namespace);
	}

	public function setMethod(): void {
		$view = View::getInstance();

		if ($this->method_data['cost'] || $this->method_data['text'] || $this->method_data['difference']) {
			if ($this->method_data['free_message'] && $this->method_data['difference']) {
				$currency                       = Currency::getInstance($this->method_data);
				$view->info['shipping-message'] = sprintf($this->method_data['free_message'], $currency->format($this->method_data['difference']));
			}

			if ($this->method_data['tax_type_id']) {
				$cost = (float)$this->method_data['cost'];

				if (!empty($this->method_data['tax_included'])) {
					//fee already includes tax: book the extracted net so the
					//configured gross total stays unchanged
					[$net] = $this->cart->extractTax($cost, $this->method_data['tax_type_id']);
					$cost  = $net;
				}

				$this->cart->addTax($this->namespace, $cost, $this->method_data['tax_type_id']);
			}

			$this->cart->addTotal('shipping', $this->namespace, $this->method_data['title'], $this->method_data['cost'], $this->method_data['text']);
		}
	}

	public function ship(array &$checkoutInfo = []): bool {
		$orderId = $checkoutInfo['order_id'] ?? 0;

		if (!$orderId) {
			return $this->fail('Order id missing!');
		}

		//record the selected method; carrier booking/tracking fills the row later
		return $this->recordShipment($orderId, $this->method_data['title'] ?? 'Shipping');
	}
}
