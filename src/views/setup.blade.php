@if(config('service-invoice-pkg.DEV'))
    <?php $service_invoice_pkg_prefix = '/packages/abs/service-invoice-pkg/src';?>
@else
    <?php $service_invoice_pkg_prefix = '';?>
@endif

<script type="text/javascript">
    var service_invoice_list_template_url = "{{URL::asset($service_invoice_pkg_prefix .'/public/themes/'.$theme.'/service-invoice-pkg/service-invoice/list.html')}}";
    var service_invoice_list_url = "{{url('#!/service-invoice-pkg/service-invoice/list')}}";
    var service_invoice_form_template_url = "{{URL::asset($service_invoice_pkg_prefix .'/public/themes/'.$theme.'/service-invoice-pkg/service-invoice/form.html')}}";
    var service_invoice_view_template_url = "{{URL::asset($service_invoice_pkg_prefix .'/public/themes/'.$theme.'/service-invoice-pkg/service-invoice/view.html')}}";
    var service_invoice_get_form_data_url = "{{url('/service-invoice-pkg/service-invoice/get-form-data')}}";
    var service_invoice_get_view_data_url = "{{url('/service-invoice-pkg/service-invoice/view')}}";
    var service_invoice_form_url = "{{url('#!/service-invoice-pkg/service-invoice/add')}}";
    var get_service_item_sub_category_url = "{{url('/service-invoice-pkg/get-service-item-sub-category/')}}";
    var get_sbu_url = "{{url('/service-invoice-pkg/get-sbu/')}}";
    var search_branch_url = "{{url('/service-invoice-pkg/branch/search')}}";
    var get_branch_info_url = "{{url('/service-invoice-pkg/get-branch-details')}}";
    var service_invoice_search_customer_url = "{{url('/service-invoice-pkg/service-invoice/customer/search')}}";
    var search_field_url = "{{url('/service-invoice-pkg/field/search')}}";
    var get_customer_info_url = "{{url('/service-invoice-pkg/service-invoice/get-customer-details')}}";
    var search_service_item_url = "{{url('/service-invoice-pkg/service-invoice/service-item/search')}}";
    var get_service_item_info_url = "{{url('/service-invoice-pkg/service-invoice/get-service-item-details')}}";
    var get_service_invoice_filter_url = "{{route('getServiceInvoiceFilter')}}";
    //SERVICE-INVOICE-APPROVALS
    var service_invoice_approval_list_template_url = "{{URL::asset($service_invoice_pkg_prefix .'/public/themes/'.$theme.'/service-invoice-pkg/service-invoice/approval/list.html')}}";
    var service_invoice_approval_view_template_url = "{{URL::asset($service_invoice_pkg_prefix .'/public/themes/'.$theme.'/service-invoice-pkg/service-invoice/approval/view.html')}}";

    var cn_dn_approval_view_data_url = "{{url('/service-invoice-pkg/service-invoice/cn-dn-approvals/approval/view/')}}";
    var approval_type_validation_url = "{{route('approvalTypeValid')}}";

    var ref_attachements_url = "{{URL::to('/storage/app/public/service-invoice/attachments')}}";
    var exportServiceInvoicesToExcelUrl = "{{route('exportServiceInvoicesToExcel')}}";
    var get_cn_dn_approval_filter_url = "{{route('getApprovalFilter')}}";

    var get_gstin_details = "{{url('service-invoice-pkg/service-invoice/customer/get-gst/')}}";

</script>

<script type="text/javascript">
    var service_item_category_list_template_url = "{{URL::asset($service_invoice_pkg_prefix .'/public/themes/'.$theme.'/service-invoice-pkg/service-item-category/list.html')}}";
    //var service_invoice_list_url = "{{url('#!/service-invoice-pkg/service-invoice/list')}}";
    var service_item_category_form_template_url = "{{URL::asset($service_invoice_pkg_prefix .'/public/themes/'.$theme.'/service-invoice-pkg/service-item-category/form.html')}}";
    var service_item_category_get_form_data_url = "{{url('/service-invoice-pkg/service-item-category/get-form-data')}}";
    var service_item_category_form_url = "{{url('#!/service-invoice-pkg/service-item_category/add')}}";
    var service_item_category_delete_data_url = "{{url('service-invoice-pkg/service-item-category/delete')}}";
</script>
 <!-- ------------------------------------------------------------------------------------------ -->
 <!-- ------------------------------------------------------------------------------------------ -->

<script type="text/javascript">
    var service_item_list_template_url = "{{URL::asset($service_invoice_pkg_prefix .'/public/themes/'.$theme.'/service-invoice-pkg/service-item/list.html')}}";
    //var service_invoice_list_url = "{{url('#!/service-invoice-pkg/service-invoice/list')}}";
    var service_item_form_template_url = "{{URL::asset($service_invoice_pkg_prefix .'/public/themes/'.$theme.'/service-invoice-pkg/service-item/form.html')}}";
    var service_item_get_form_data_url = "{{url('/service-invoice-pkg/service-item/get-form-data')}}";
    var service_item_form_url = "{{url('#!/service-invoice-pkg/service-item/add')}}";
    var service_item_delete_data_url = "{{url('service-invoice-pkg/service-item/delete')}}";
    var get_sub_category_based_category_url = "{{url('service-invoice-pkg/service-item/get-sub-category')}}";
    var get_service_item_filter_url = "{{route('getServiceItemFilter')}}";
    var search_coa_code_url = "{{url('/service-invoice-pkg/service-items/search-coa-code')}}";
    var search_sac_code_url = "{{url('/service-invoice-pkg/service-items/search-sac-code')}}";
</script>
