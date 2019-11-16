<!DOCTYPE html>
<html dir="ltr" lang="en-US" style="width: 793px; margin: 0 auto;">
    <head>
        <title>Service Invoice</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body {width: 100%;color: #000;min-height: 1110px;padding: 0px;line-height: 1.4;margin: 0;}
            table {font-family: arial, sans-serif;border-collapse: collapse;width: 100%;margin-top: 0px;margin-bottom: 0;}
            table:last-child {margin-bottom: 0;}
            th {font-size: 12px;font-weight: bold;line-height: normal;padding: 5px;}
            td {font-size: 12px;line-height: normal;padding: 5px;}
            .table-outter > tbody > tr > td {padding: 0;border: 1px solid #000;}
            .table-header > tbody > tr > td {}
            .table-title > tbody > tr > td {font-size: 14px;font-weight: bold;text-align: center;border: solid #000000;border-width: 1px 0;}
            .table-info-outter > tbody > tr > td {width: 50%;padding: 0;vertical-align: top;}
            .table-info > tbody > tr > td {padding: 2px 5px;vertical-align: top;}
            .table-info > tbody > tr > td:first-child {width: 40%;}
            .table-info > tbody > tr > td:last-child {width: 60%;}
            .table-bill > thead > tr > th, .table-bill > tbody > tr > td {text-align: left;vertical-align: top;border: 1px solid #000000;}
            .table-bill > thead > tr > th:first-child, .table-bill > tbody > tr > td:first-child {border-left: 0;}
            .table-bill > thead > tr > th:last-child, .table-bill > tbody > tr > td:last-child {border-right: 0;}
            .table-bill > thead > tr > th.text-right, .table-bill > tbody > tr > td.text-right {text-align: right;}
            .table-gst-outter > tbody > tr > td {padding: 0;vertical-align: top;}
            .table-gst-outter > tbody > tr > td:first-child {width: 40%;}
            .table-gst-outter > tbody > tr > td:last-child {width: 60%;}
            .table-gst > tbody > tr > td:last-child {text-align: right;}
            .table-signature > tbody > tr > td {padding-top: 30px;text-align: center;}
            .table-footer {margin-top: 50px;}
            .table-footer > tbody > tr > td {width: 33.33333%;}

            p {margin: 0;}
            .mt-20 {margin-top: 20px;}
            .text-center {text-align: center;}
            .text-left {text-align: left;}
            .text-right {text-align: right;}
            .opacity-0 {opacity: 0;}
            .vertical-top {vertical-align: top;}
            .block {display: block;}
            .table-header-title {font-size: 18px;font-weight: bold;line-height: normal;margin: 0; margin-bottom: 5px;}
        </style>
    </head>
    <body>
        <!-- ORDER FORM -->
        <table class="table-outter">
            <tbody>
                <tr>
                    <td>
                        <table class="table-header">
                            <tbody>
                                <tr>
                                    <td class="vertical-top">
                                        <img class="img-responive" src="TVS.svg" alt="Logo" />
                                    </td>
                                    <td class="text-center">
                                        <h1 class="table-header-title">{{$service_invoice_pdf->company->name}}</h1>
                                        <p>"{{$service_invoice_pdf->outlets->formatted_address}}"</p>
                                        <p>{{$service_invoice_pdf->outlets->region_id}}</p>
                                        <p class="mt-20"></p>
                                        <p>(<b>Registered Office </b>: {{$service_invoice_pdf->company->formatted_address}})</p>
                                    </td>
                                    <td>
                                        <img class="img-responive opacity-0" src="TVS.svg" alt="Logo" />
                                    </td>
                                </tr>
                            </tbody>
                        </table><!-- Table Header -->
                        <table class="table-title">
                            <tbody>
                                <tr>
                                    <td>Tax Invoice(Original)</td>
                                </tr>
                            </tbody>
                        </table><!-- Table Title -->
                        <table class="table-info-outter">
                            <tbody>
                                <tr>
                                    <td>
                                        <table class="table-info">
                                            <tbody>
                                                <tr>
                                                    <td><b>Document No</b></td>
                                                    <td>: {{$service_invoice_pdf->number}}</td>
                                                </tr>
                                                <tr>
                                                    <td><b>Customer Name & Bill To</b></td>
                                                    <td>
                                                        <p>: {{$service_invoice_pdf->customer->name}}</p>
                                                        <p>{{$service_invoice_pdf->customer->formatted_address}}</p>
                                                        <p>{{$service_invoice_pdf->outlets->region_id}}</p>
                                                    </td>
                                                </tr>
                                                <tr>
                                                    <td><b>PIN code</b></td>
                                                    <td>: {{$service_invoice_pdf->customer->primaryAddress->pincode}}</td>
                                                </tr>
                                                <tr>
                                                    <td><b>Ship To</b></td>
                                                    <td>: {{$service_invoice_pdf->customer->formatted_address}}</td>
                                                </tr>
                                            </tbody>
                                        </table><!-- Table Info -->
                                    </td>
                                    <td>
                                        <table class="table-info">
                                            <tbody>
                                                <tr>
                                                    <td><b>Document Date</b></td>
                                                    <td>: {{ date('d/M/Y', strtotime($service_invoice_pdf->document_date))}}</td>
                                                </tr>
                                                <tr>
                                                    <td><b>Cust Code</b></td>
                                                    <td>: {{$service_invoice_pdf->customer->code}}</td>
                                                </tr>
                                                <tr>
                                                    <td><b>Phone</b></td>
                                                    <td>: {{$service_invoice_pdf->customer->mobile_no}}</td>
                                                </tr>
                                                <tr>
                                                    <td><b>BusinessUnit / Outlet</b></td>
                                                    <td>: {{$service_invoice_pdf->sbus->name}} / {{$service_invoice_pdf->outlets->code}}</td>
                                                </tr>
                                                <tr>
                                                    <td><b>Pool</b></td>
                                                    <td>: </td>
                                                </tr>
                                                <tr>
                                                    <td><b>Sales responsible</b></td>
                                                    <td>: </td>
                                                </tr>
                                                <tr>
                                                    <td><b>Route</b></td>
                                                    <td>: </td>
                                                </tr>
                                                <tr>
                                                    <td><b>Delivery terms</b></td>
                                                    <td>: </td>
                                                </tr>
                                                <tr>
                                                    <td><b>Payment terms</b></td>
                                                    <td>: </td>
                                                </tr>
                                                <tr>
                                                    <td><b>Due date</b></td>
                                                    <td>: 04/10/2019</td>
                                                </tr>
                                                <tr>
                                                    <td><b>Cus Ord Ref</b></td>
                                                    <td>: </td>
                                                </tr>
                                                <tr>
                                                    <td><b>GSTIN</b></td>
                                                    <td>: 33AAACM2931R1ZA</td>
                                                </tr>
                                            </tbody>
                                        </table><!-- Table Info -->
                                    </td>
                                </tr>
                            </tbody>
                        </table><!-- Table Info Outter -->
                        <table class="table-bill">
                            <thead>
                                <tr>
                                    <th>Item number Description P-List/P-List e-code</th>
                                    <th>HSN/SAC</th>
                                    <th>Sales order</th>
                                    <th class="text-right">MRP/List Price Net Fact%</th>
                                    <th class="text-right">Quantity Unit price</th>
                                    <th class="text-right">CGST% Value</th>
                                    <th class="text-right">SGST% Value</th>
                                    <th class="text-right">KFC% Value</th>
                                    <th class="text-right">IGST% Value</th>
                                    <th class="text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
<?php
$total_cgst_amount = 0;
$total_sgst_amount = 0;
$total_igst_amount = 0;
?>
                                @foreach($service_invoice_pdf->serviceInvoiceItems as $serviceInvoiceItem)
                                <tr>
                                    <td>{{$serviceInvoiceItem->serviceItem->code}}<br/>{{$serviceInvoiceItem->serviceItem->name}}</td>
                                    <td>{{$serviceInvoiceItem->serviceItem->taxCode->code}}</td>
                                    <td>{{$serviceInvoiceItem->description}}</td>
                                    <td class="text-right">
                                        <span class="block">0.00</span>
                                        <span class="block">0.00</span>
                                    </td>
                                    <td class="text-right">
                                        <span class="block">{{$serviceInvoiceItem->qty}}</span>
                                        <span class="block">{{$serviceInvoiceItem->rate}}</span>
                                    </td>
                                    <td class="text-right">
                                        <span class="block">0.00</span>
                                        <span class="block">0.00</span>
                                    </td>
                                    <td class="text-right">
                                        <span class="block">0.00</span>
                                        <span class="block">0.00</span>
                                    </td>
                                    <td class="text-right">
                                        <span class="block">0.00</span>
                                        <span class="block">0.00</span>
                                    </td>
                                    <td class="text-right">
                                        <span class="block">0.00</span>
                                        <span class="block">0.00</span>
                                    </td>
                                    <td>15,889.82</td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table><!-- Table Bill -->
                        <table class="table-gst-outter">
                            <tbody>
                                <tr>
                                    <td>
                                        <table class="table-gst">
                                            <tbody>
                                                <tr>
                                                    <td>CGST</td>
                                                    <td class="text-right">1,430.08</td>
                                                    <td></td>
                                                </tr>
                                                <tr>
                                                    <td>SGST</td>
                                                    <td class="text-right">1,430.08</td>
                                                    <td></td>
                                                </tr>
                                                <tr>
                                                    <td>IGST</td>
                                                    <td class="text-right">0.00</td>
                                                    <td></td>
                                                </tr>
                                                <tr>
                                                    <td>KFC</td>
                                                    <td class="text-right">0.00</td>
                                                    <td></td>
                                                </tr>
                                            </tbody>
                                        </table><!-- Table GST -->
                                    </td>
                                    <td>
                                        <table class="table-gst">
                                            <tbody>
                                                <tr>
                                                    <td>Total Qty :</td>
                                                    <td></td>
                                                    <td></td>
                                                </tr>
                                                <tr>
                                                    <td>Total amount :</td>
                                                    <td>Rs/-</td>
                                                    <td style="border-bottom: 1px solid #000000;">15,889.82</td>
                                                </tr>
                                                <tr>
                                                    <td>GST Component</td>
                                                    <td>Rs/-</td>
                                                    <td>2,860.16</td>
                                                </tr>
                                                <tr>
                                                    <td>KFC</td>
                                                    <td>Rs/-</td>
                                                    <td>0.00</td>
                                                </tr>
                                                <tr>
                                                    <td>TCS</td>
                                                    <td>Rs/-</td>
                                                    <td>0.00</td>
                                                </tr>
                                                <tr>
                                                    <td><b>NetValue(Rounded Off)</b></td>
                                                    <td>Rs/-</td>
                                                    <td>18,750.00</td>
                                                </tr>
                                            </tbody>
                                        </table><!-- Table GST -->
                                    </td>
                                </tr>
                                <tr>
                                    <td colspan="2" style="padding: 5px;">
                                        <p><b>Rupees Eighteen Thousand Seven Hundred Fifty Only</b></p>
                                        <p>Whether tax is payable on reverse charge basis? = No</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table><!-- Table GST Outter -->
                        <table class="table-signature">
                            <tbody>
                                <tr>
                                    <td>CUSTOMER SIGNATURE</td>
                                    <td>T V Sundram Iyengar & Sons Private Ltd</td>
                                </tr>
                                <tr>
                                    <td></td>
                                    <td>Authorized signatory</td>
                                </tr>
                                <tr>
                                    <td>PL. INSURE AT YOUR END</td>
                                    <td></td>
                                </tr>
                            </tbody>
                        </table>
                    </td>
                </tr>
            </tbody>
        </table><!-- Table Outter -->
        <table class="table-footer">
            <tbody>
                <tr>
                    <td>GSTIN : 33AABCT0159K1ZG</td>
                    <td>CIN : U34101TN1929PTC002973</td>
                    <td></td>
                </tr>
                <tr>
                    <td>E & O E</td>
                    <td>PAN No. : AABCT0159K</td>
                    <td></td>
                </tr>
            </tbody>
        </table><!-- Table Footer -->
    </body>
</html>