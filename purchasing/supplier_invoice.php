<?php

$page_security=5;
$path_to_root="..";

include_once($path_to_root . "/purchasing/includes/purchasing_db.inc");

include_once($path_to_root . "/includes/session.inc");

include_once($path_to_root . "/includes/banking.inc");
include_once($path_to_root . "/includes/data_checks.inc");

include_once($path_to_root . "/purchasing/includes/purchasing_ui.inc");
$js = "";
if ($use_popup_windows)
	$js .= get_js_open_window(900, 500);
if ($use_date_picker)
	$js .= get_js_date_picker();
page(_("Enter Supplier Invoice"), false, false, "", $js);


//----------------------------------------------------------------------------------------

check_db_has_suppliers(_("There are no suppliers defined in the system."));

//---------------------------------------------------------------------------------------------------------------
if ($ret = context_restore()) {
 // return from supplier editor
	copy_from_trans($_SESSION['supp_trans']);
	if(isset($ret['supplier_id']))
		$_POST['supplier_id'] = $ret['supplier_id'];
}
if (isset($_POST['_supplier_id_editor'])) {
	copy_to_trans($_SESSION['supp_trans']);
	context_call($path_to_root.'/purchasing/manage/suppliers.php?supplier_id='.$_POST['supplier_id'], 'supp_trans');
}

//---------------------------------------------------------------------------------------------------------------

if (isset($_GET['AddedID'])) 
{
	$invoice_no = $_GET['AddedID'];
	$trans_type = 20;


    echo "<center>";
    display_notification_centered(_("Supplier invoice has been processed."));
    display_note(get_trans_view_str($trans_type, $invoice_no, _("View this Invoice")));

	display_note(get_gl_view_str($trans_type, $invoice_no, _("View the GL Journal Entries for this Invoice")), 1);

    hyperlink_params($_SERVER['PHP_SELF'], _("Enter Another Invoice"), "New=1");

	display_footer_exit();
}

//--------------------------------------------------------------------------------------------------

if (isset($_GET['New']))
{
	if (isset( $_SESSION['supp_trans']))
	{
		unset ($_SESSION['supp_trans']->grn_items);
		unset ($_SESSION['supp_trans']->gl_codes);
		unset ($_SESSION['supp_trans']);
	}

	//session_register("SuppInv");
	session_register("supp_trans");
	$_SESSION['supp_trans'] = new supp_trans;
	$_SESSION['supp_trans']->is_invoice = true;
}

//--------------------------------------------------------------------------------------------------
function clear_fields()
{
	global $Ajax;
	
	unset($_POST['gl_code']);
	unset($_POST['dimension_id']);
	unset($_POST['dimension2_id']);
	unset($_POST['amount']);
	unset($_POST['memo_']);
	unset($_POST['AddGLCodeToTrans']);
	$Ajax->activate('gl_items');
	set_focus('gl_code');
}
//------------------------------------------------------------------------------------------------
//	GL postings are often entered in the same form to two accounts
//  so fileds are cleared only on user demand.
//
if (isset($_POST['ClearFields']))
{
	clear_fields();
}

if (isset($_POST['AddGLCodeToTrans'])){

	$Ajax->activate('gl_items');
	$input_error = false;

	$sql = "SELECT account_code, account_name FROM ".TB_PREF."chart_master WHERE account_code='" . $_POST['gl_code'] . "'";
	$result = db_query($sql,"get account information");
	if (db_num_rows($result) == 0)
	{
		display_error(_("The account code entered is not a valid code, this line cannot be added to the transaction."));
		set_focus('gl_code');
		$input_error = true;
	}
	else
	{
		$myrow = db_fetch_row($result);
		$gl_act_name = $myrow[1];
		if (!check_num('amount'))
		{
			display_error(_("The amount entered is not numeric. This line cannot be added to the transaction."));
			set_focus('amount');
			$input_error = true;
		}
	}

	if ($input_error == false)
	{
		$_SESSION['supp_trans']->add_gl_codes_to_trans($_POST['gl_code'], $gl_act_name,
			$_POST['dimension_id'], $_POST['dimension2_id'], 
			input_num('amount'), $_POST['memo_']);
		set_focus('gl_code');
	}
}

//------------------------------------------------------------------------------------------------

function check_data()
{
	If (!$_SESSION['supp_trans']->is_valid_trans_to_post())
	{
		display_error(_("The invoice cannot be processed because the there are no items or values on the invoice.  Invoices are expected to have a charge."));
		return false;
	}

	if (!references::is_valid($_SESSION['supp_trans']->reference)) 
	{
		display_error(_("You must enter an invoice reference."));
		set_focus('reference');
		return false;
	}

	if (!is_new_reference($_SESSION['supp_trans']->reference, 20)) 
	{
		display_error(_("The entered reference is already in use."));
		set_focus('reference');
		return false;
	}

	if (!references::is_valid($_SESSION['supp_trans']->supp_reference)) 
	{
		display_error(_("You must enter a supplier's invoice reference."));
		set_focus('supp_reference');
		return false;
	}

	if (!is_date( $_SESSION['supp_trans']->tran_date))
	{
		display_error(_("The invoice as entered cannot be processed because the invoice date is in an incorrect format."));
		set_focus('trans_date');
		return false;
	} 
	elseif (!is_date_in_fiscalyear($_SESSION['supp_trans']->tran_date)) 
	{
		display_error(_("The entered date is not in fiscal year."));
		set_focus('trans_date');
		return false;
	}
	if (!is_date( $_SESSION['supp_trans']->due_date))
	{
		display_error(_("The invoice as entered cannot be processed because the due date is in an incorrect format."));
		set_focus('due_date');
		return false;
	}

	$sql = "SELECT Count(*) FROM ".TB_PREF."supp_trans WHERE supplier_id='" . $_SESSION['supp_trans']->supplier_id . "' AND supp_reference='" . $_POST['supp_reference'] . "'";
	$result=db_query($sql,"The sql to check for the previous entry of the same invoice failed");

	$myrow = db_fetch_row($result);
	if ($myrow[0] == 1)
	{ 	/*Transaction reference already entered */
		display_error(_("This invoice number has already been entered. It cannot be entered again." . " (" . $_POST['supp_reference'] . ")"));
		return false;
	}

	return true;
}

//--------------------------------------------------------------------------------------------------

function handle_commit_invoice()
{
	copy_to_trans($_SESSION['supp_trans']);

	if (!check_data())
		return;

	$invoice_no = add_supp_invoice($_SESSION['supp_trans']);

    $_SESSION['supp_trans']->clear_items();
    unset($_SESSION['supp_trans']);

	meta_forward($_SERVER['PHP_SELF'], "AddedID=$invoice_no");
}

//--------------------------------------------------------------------------------------------------

if (isset($_POST['PostInvoice']))
{
	handle_commit_invoice();
}

function check_item_data($n)
{
	global $check_price_charged_vs_order_price,
		$check_qty_charged_vs_del_qty;
	if (!check_num('this_quantity_inv'.$n, 0) || input_num('this_quantity_inv'.$n)==0)
	{
		display_error( _("The quantity to invoice must be numeric and greater than zero."));
		set_focus('this_quantity_inv'.$n);
		return false;
	}

	if (!check_num('ChgPrice'.$n))
	{
		display_error( _("The price is not numeric."));
		set_focus('ChgPrice'.$n);
		return false;
	}

	if ($check_price_charged_vs_order_price == True)
	{
		if ($_POST['order_price'.$n]!=input_num('ChgPrice'.$n)) {
		     if ($_POST['order_price'.$n]==0 ||
				input_num('ChgPrice'.$n)/$_POST['order_price'.$n] >
			    (1 + (sys_prefs::over_charge_allowance() / 100)))
		    {
			display_error(_("The price being invoiced is more than the purchase order price by more than the allowed over-charge percentage. The system is set up to prohibit this. See the system administrator to modify the set up parameters if necessary.") .
			_("The over-charge percentage allowance is :") . sys_prefs::over_charge_allowance() . "%");
			set_focus('ChgPrice'.$n);
			return false;
		    }
		}
	}

	if ($check_qty_charged_vs_del_qty == True)
	{
		if (input_num('this_quantity_inv'.$n) / ($_POST['qty_recd'.$n] - $_POST['prev_quantity_inv'.$n]) >
			(1+ (sys_prefs::over_charge_allowance() / 100)))
		{
			display_error( _("The quantity being invoiced is more than the outstanding quantity by more than the allowed over-charge percentage. The system is set up to prohibit this. See the system administrator to modify the set up parameters if necessary.")
			. _("The over-charge percentage allowance is :") . sys_prefs::over_charge_allowance() . "%");
			set_focus('this_quantity_inv'.$n);
			return false;
		}
	}

	return true;
}

function commit_item_data($n)
{
	if (check_item_data($n))
	{
    	if (input_num('this_quantity_inv'.$n) >= ($_POST['qty_recd'.$n] - $_POST['prev_quantity_inv'.$n]))
    	{
    		$complete = true;
    	}
    	else
    	{
    		$complete = false;
    	}

		$_SESSION['supp_trans']->add_grn_to_trans($n, $_POST['po_detail_item'.$n],
			$_POST['item_code'.$n], $_POST['item_description'.$n], $_POST['qty_recd'.$n],
			$_POST['prev_quantity_inv'.$n], input_num('this_quantity_inv'.$n),
			$_POST['order_price'.$n], input_num('ChgPrice'.$n), $complete,
			$_POST['std_cost_unit'.$n], "");
	}
}

//-----------------------------------------------------------------------------------------

$id = find_submit('grn_item_id');
if ($id != -1)
{
	commit_item_data($id);
}

if (isset($_POST['InvGRNAll']))
{
   	foreach($_POST as $postkey=>$postval )
    {
		if (strpos($postkey, "qty_recd") === 0)
		{
			$id = substr($postkey, strlen("qty_recd"));
			$id = (int)$id;
			commit_item_data($id);
		}
    }
}	

//--------------------------------------------------------------------------------------------------
$id = find_submit('Delete');
if ($id != -1)
{
	$_SESSION['supp_trans']->remove_grn_from_trans($id);
	$Ajax->activate('grn_items');
	$Ajax->activate('inv_tot');
}

$id = find_submit('Delete2');
if ($id != -1)
{
	$_SESSION['supp_trans']->remove_gl_codes_from_trans($id);
	clear_fields();
	$Ajax->activate('gl_items');
	$Ajax->activate('inv_tot');
}

start_form(false, true);

start_table("$table_style2 width=98%", 8);
echo "<tr><td valign=center>"; // outer table

echo "<center>";

invoice_header($_SESSION['supp_trans']);
if ($_POST['supplier_id']=='') 
	display_error('No supplier found for entered search text');
else {
	echo "</td></tr><tr><td valign=center>"; // outer table

	echo "<center>";

	display_grn_items($_SESSION['supp_trans'], 1);
	//display_grn_items_for_selection();
	display_gl_items($_SESSION['supp_trans'], 1);
	//display_gl_controls();

	//echo "</td></tr><tr><td align=center colspan=2>"; // outer table
	echo "<br>";
	div_start('inv_tot');
	invoice_totals($_SESSION['supp_trans']);
	div_end();
}
echo "</td></tr>";

end_table(); // outer table

//-----------------------------------------------------------------------------------------
$id = find_submit('grn_item_id');
$id2 = find_submit('void_item_id');
if ($id != -1 || $id2 != -1)
{
	$Ajax->activate('grn_items');
	$Ajax->activate('inv_tot');
}

if (get_post('AddGLCodeToTrans'))
	$Ajax->activate('inv_tot');

if ($_SESSION["wa_current_user"]->access == 2)
{
	if ($id2 != -1) // Added section 2008-10-18 Joe Hunt for voiding delivery lines
	{
		begin_transaction();
		
		$myrow = get_grn_item_detail($id2);

		$grn = get_grn_batch($myrow['grn_batch_id']);

	    $sql = "UPDATE ".TB_PREF."purch_order_details
			SET quantity_received = qty_invoiced, quantity_ordered = qty_invoiced WHERE po_detail_item = ".$myrow["po_detail_item"];
	    db_query($sql, "The quantity invoiced of the purchase order line could not be updated");

	    $sql = "UPDATE ".TB_PREF."grn_items
	    	SET qty_recd = quantity_inv WHERE id = ".$myrow["id"];
		db_query($sql, "The quantity invoiced off the items received record could not be updated");
	
		update_average_material_cost($grn["supplier_id"], $myrow["item_code"],
			$myrow["unit_price"], -$myrow["QtyOstdg"], Today());

	   	add_stock_move(25, $myrow["item_code"], $myrow['grn_batch_id'], $grn['loc_code'], sql2date($grn["delivery_date"]), "",
	   		-$myrow["QtyOstdg"], $myrow['std_cost_unit'], $grn["supplier_id"], 1, $myrow['unit_price']);
	   		
	   	commit_transaction();
	}   		
}

echo "<br>";
submit_center('PostInvoice', _("Enter Invoice"), true, '', true);
echo "<br>";

end_form();

//--------------------------------------------------------------------------------------------------

end_page();
?>
