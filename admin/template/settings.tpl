import(common.tpl)


/* method tabs */
.settings|before = <?php $index = 0;?>
@method = [data-v-methods] [data-v-method]
@method|deleteAllButFirstChild

@method|before = <?php
if (isset($this->methods) && is_array($this->methods)) {
$index = 0;	
foreach ($this->methods as $method) { ?>

	@method|data-tab = <?php echo $index;?>
	
	[data-v-methods] .tab-pane[data-v-method]|id = <?php echo "tab-$index";?>
	[data-v-methods] .tab-pane[data-v-method]|data-tab = $index
	[data-v-methods] .tab-pane[data-v-method]|addClass = <?php if ($index == 0) echo "active";?>

	@method [data-v-method-shipping_method_id]|href = <?php echo "#tab-$index";?>
	@method [data-v-method-shipping_method_id]|addClass = <?php if ($index == 0) echo "active";?>
	@method [data-v-method-name] = <?php 
		$langa = current($method['lang'] ?? []);
		echo htmlspecialchars(is_array($langa) ? ($langa['title'] ?? "Method $index") : ($method['title'] ?? "Method $index"));?>

	@method input.method|name = <?php
		$name = '@@__name__@@';
		echo htmlspecialchars(str_replace('methods[#tab#]',"methods[$index]", $name));
	?>	
	
	@method select|name = <?php
		$name = '@@__name__@@';
		echo htmlspecialchars(str_replace('methods[#tab#]',"methods[$index]", $name));
	?>
	
	@method input[data-v-method-*] = $method['@@__data-v-method-(*)__@@']

@method|after = <?php 
		$index++;
	}
}
?>

@shipping_status = [data-v-shipping_status_id] [data-v-option]
@shipping_status|deleteAllButFirstChild

@shipping_status|before = <?php
$count = 0;
if(isset($this->shipping_status) && is_array($this->shipping_status)) {
	foreach ($this->shipping_status as $shipping_status_index => $shipping_status) {?>
	
	@shipping_status|innerText = $shipping_status
	@shipping_status|value	  = $shipping_status_index
	@shipping_status|addNewAttribute = <?php if (isset($method['shipping_status_id']) && $shipping_status_index == $method['shipping_status_id']) echo 'selected';?>
	
	@shipping_status|after = <?php 
		$count++;
	} 
}?>

/* language tabs */

[data-v-languages]|before = <?php $_lang_instance = '@@__data-v-languages__@@';$_i = 0;?>
@language = [data-v-languages] [data-v-language]
@language|deleteAllButFirstChild

@language|before = <?php

foreach ($this->languagesList as $language) { ?>
	[data-v-languages] [data-v-language-id]|id = <?php echo "lang-$index-" . $language['code'] . '-' . $_lang_instance;?>
	[data-v-languages]  [data-v-language-id]|addClass = <?php if ($_i == 0) echo 'show active';?>

	@language [data-v-language-name] = $language['name']
	@language [data-v-language-img]|title = $language['name']
	@language [data-v-language-img]|src = <?php echo 'language/' . $language['code'] . '/' . $language['code'] . '.png';?>
	@language [data-v-language-link]|href = <?php echo "#lang-$index-" . $language['code'] . '-' . $_lang_instance?>
	@language [data-v-language-link]|addClass = <?php if ($_i == 0) echo 'active';?>
	
	[data-v-method] @language [data-v-lang-*] = <?php 
		$name = '@@__data-v-lang-(*)__@@';
		echo htmlspecialchars($method['lang'][$language['language_id']][$name] ?? $_POST[$index][$language['language_id']][$name] ?? '');
	?>

	[data-v-method] @language [data-v-lang-*]|name = <?php 
		$name = '@@__data-v-lang-(*)__@@';
		echo "methods[$index][lang][{$language['language_id']}][$name]";
	?>	

	.tab-content-template @language [data-v-lang-*]|name = <?php 
		$name = '@@__data-v-lang-(*)__@@';
		echo "methods[#tab#][lang][{$language['language_id']}][$name]";
	?>	
	
@language|after = <?php 
$_i++;
}
?>


@group = [data-v-method] [data-v-group]
@rule|deleteAllButFirstChild

@group|before = <?php
$count = 0;
$rules = [];
if(isset($weight['conditions']) && is_array($weight['conditions'])) {
	$group_index = 0;	
	foreach ($weight['conditions'] as $rules) {
	?>
	
	@group|data-v-group =$group_index
	@group|data-group   =$group_index
	
	@group|after = <?php 
		$count++;
		$group_index++;
	} 
}?>

@rule = [data-v-method] [data-v-group] [data-v-rule]
@rule|deleteAllButFirstChild

@rule|before = <?php
$count = 0;
if(isset($rules) && is_array($rules)) {
	$rule_index = 0;
	foreach ($rules as $rule) {
		$rule_condition = htmlspecialchars($rule['condition'] ?? '');
		$rule_operator  = htmlspecialchars($rule['operator'] ?? '');
		?>
	
	@rule|data-rule                  = $rule_index
	@rule input[data-v-rule-*]       = $rule['@@__data-v-rule-(*)__@@']
	@rule input[data-v-rule-*]|name  = <?php echo "methods[$index][weight][$weight_index][conditions][$group_index][$rule_index][@@__data-v-rule-(*)__@@]";?>
	@rule select[data-v-rule-*]|name = <?php echo "methods[$index][weight][$weight_index][conditions][$group_index][$rule_index][@@__data-v-rule-(*)__@@]";?>
	
	/*restore saved condition/operator selection, js syncs the selects from these*/
	@rule select[data-v-rule-condition]|addNewAttribute = <?php echo ' data-saved-value="' . $rule_condition . '"';?>
	@rule select[data-v-rule-operator]|addNewAttribute = <?php echo ' data-saved-value="' . $rule_operator . '"';?>
	
	/*label for autocomplete value widget*/
	@rule input[data-v-rule-value]|addNewAttribute = <?php echo ' data-text="' . htmlspecialchars($rule['value_text'] ?? '') . '"';?>
	
	@rule|after = <?php 
			$count++;
			$rule_index++;
	} 
}?>

@weight =  [data-v-method] [data-v-weights] [data-v-weight]
@weight|deleteAllButFirstChild

@weight|before = <?php
$count = 0;
if(isset($method['weight']) && is_array($method['weight'])) {
	$weight_index = 0;
	foreach ($method['weight'] as $weight) {?>

	//@weight [type="radio"][data-v-weight-*]|addNewAttribute= <?php if ($weight['@@__data-v-weight-(*)__@@'] == '@@__value__@@') echo 'checked';?>
	
	@weight|data-weight                  = $weight_index
	@weight input[data-v-weight-*]       = $weight['@@__data-v-weight-(*)__@@']
	@weight input[data-v-weight-*]|name  = <?php echo "methods[$index][weight][$weight_index][@@__data-v-weight-(*)__@@]";?>
	@weight select[data-v-weight-*]|name = <?php echo "methods[$index][weight][$weight_index][@@__data-v-weight-(*)__@@]";?>
	
	@weight|after = <?php 
		$count++;
		$weight_index++;
	} 
}?>


@region_group = [data-v-region_group_id] [data-v-option]
@region_group|deleteAllButFirstChild

@region_group|before = <?php
$count = 0;
if(isset($this->region_group) && is_array($this->region_group)) {
	foreach ($this->region_group as $region_group_index => $region_group) {?>
	
	
	@region_group|innerText = $region_group
	@region_group|value	    = $region_group_index
	@region_group|addNewAttribute = <?php if (isset($method['region_group_id']) && $region_group_index == $method['region_group_id']) echo 'selected';?>
	
	
	@region_group|after = <?php 
		$count++;
	} 
}?>

@tax_type = [data-v-tax_type_id] [data-v-option]
@tax_type|deleteAllButFirstChild

@tax_type|before = <?php
$count = 0;
if(isset($this->tax_type) && is_array($this->tax_type)) {
	foreach ($this->tax_type as $tax_type_index => $tax_type) {?>
	
	
	@tax_type|innerText = $tax_type
	@tax_type|value	    = $tax_type_index
	@tax_type|addNewAttribute = <?php if (isset($method['tax_type_id']) && $tax_type_index == $method['tax_type_id']) echo 'selected';?>
	
	
	@tax_type|after = <?php 
		$count++;
	} 
}?>


@order_status = [data-v-order_status_id] [data-v-option]
@order_status|deleteAllButFirstChild

@order_status|before = <?php
$count = 0;
if(isset($this->order_status) && is_array($this->order_status)) {
	foreach ($this->order_status as $order_status_index => $order_status) {?>
	
	
	@order_status|innerText = <?php echo Vvveb\humanReadable($order_status);?>
	@order_status|value     = $order_status_index
	@order_status|addNewAttribute = <?php if (isset($method['order_status_id']) && $order_status_index == $method['order_status_id']) echo 'selected';?>
	
	
	@order_status|after = <?php 
		$count++;
	} 
}?>

/* gross/net fee radio: rewrite cloned names and restore checked state */
@method input[data-v-tax-included]|name = <?php
	$name = '@@__name__@@';
	echo htmlspecialchars(str_replace('methods[#tab#]',"methods[$index]", $name));
?>

@method input[data-v-tax-included]|addNewAttribute = <?php echo ((string)($method['tax_included'] ?? '0') === '@@__value__@@') ? 'checked' : '';?>
