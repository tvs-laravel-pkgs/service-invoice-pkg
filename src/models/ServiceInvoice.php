<?php

namespace Abs\ServiceInvoicePkg;

use Abs\AttributePkg\Models\Field;
use Abs\AxaptaExportPkg\AxaptaExport;
use Abs\ImportCronJobPkg\ImportCronJob;
use Abs\SerialNumberPkg\SerialNumberGroup;
use Abs\ServiceInvoicePkg\ServiceInvoiceController;
use Abs\TaxPkg\Tax;
use Abs\TaxPkg\TaxCode;
use App\Address;
use App\ApiLog;
use App\AxExportStatus;
use App\City;
use App\Company;
use App\Config;
use App\Customer;
use App\EInvoiceUom;
use App\Employee;
use App\Entity;
use App\FinancialYear;
use App\Outlet;
use App\Sbu;
use App\State;
use App\User;
use App\Vendor;
use Auth;
use Carbon\Carbon;
use DB;
use File;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use PHPExcel_IOFactory;
use PHPExcel_Shared_Date;

class ServiceInvoice extends Model
{
    use SoftDeletes;
    protected $table = 'service_invoices';
    protected $fillable = [
        'company_id',
        'number',
        'branch_id',
        'sbu_id',
        'category_id',
        'sub_category_id',
        'invoice_date',
        'document_date',
        'to_account_type_id',
        'customer_id',
        'items_count',
        'amount_total',
        'tax_total',
        'sub_total',
        'total',
        'is_service',
        'is_reverse_charge_applicable',
        'po_reference_number',
        'invoice_number',
        'round_off_amount',
        'final_amount',
        'final_amount',
        'e_invoice_registration',
        'qr_image',
        'ack_no',
        'ack_date',
        'version',
        'irn_request',
        'irn_response',
        'created_by_id',
        'updated_by_id',
        'deleted_by_id',
    ];

    private $lineNumber;
    public function __construct()
    {
        $this->lineNumber = 1;
    }

    public function getInvoiceDateAttribute($value)
    {
        return empty($value) ? '' : date('d-m-Y', strtotime($value));
    }

    public function createdBy()
    {
        return $this->belongsTo('App\User', 'created_by_id');
    }

    public function getDocumentDateAttribute($value)
    {
        return empty($value) ? '' : date('d-m-Y', strtotime($value));
    }

    public function setInvoiceDateAttribute($date)
    {
        return $this->attributes['invoice_date'] = empty($date) ? null : date('Y-m-d', strtotime($date));
    }
    public function setDocumentDateAttribute($date)
    {
        return $this->attributes['document_date'] = empty($date) ? date('Y-m-d') : date('Y-m-d', strtotime($date));
    }

    public function getAckDateAttribute($date)
    {
        return $this->attributes['ack_date'] = empty($date) ? null : date('d-m-Y H:i:s', strtotime($date));
    }

    public function serviceItemSubCategory()
    {
        return $this->belongsTo('Abs\ServiceInvoicePkg\ServiceItemSubCategory', 'sub_category_id', 'id');
    }

    public function serviceItemCategory()
    {
        return $this->belongsTo('Abs\ServiceInvoicePkg\ServiceItemCategory', 'category_id', 'id');
    }

    public function toAccountType()
    {
        return $this->belongsTo('App\Config', 'to_account_type_id');
    }

    public function customer()
    {
        if ($this->to_account_type_id == 1440) {
            //customer
            return $this->belongsTo('Abs\CustomerPkg\Customer', 'customer_id');
        } elseif ($this->to_account_type_id == 1441) {
            //vendor
            return $this->belongsTo('App\Vendor', 'customer_id');
        }
        // elseif ($this->to_account_type_id == 1442) {
        //     //ledger
        //     return $this->belongsTo('Abs\JVPkg\Ledger', 'customer_id');
        // }
    }

    // public function customer() {
    // return $this->belongsTo('App\Customer', 'customer_id', 'id');
    // }

    public function branch()
    {
        return $this->belongsTo('App\Outlet', 'branch_id', 'id');
    }

    public function address()
    {
        return $this->belongsTo('App\Address', 'address_id', 'id');
    }

    public function sbu()
    {
        return $this->belongsTo('App\Sbu', 'sbu_id', 'id');
    }

    public function type()
    {
        return $this->belongsTo('App\Config', 'type_id', 'id');
    }

    public function serviceInvoiceItems()
    {
        return $this->hasMany('Abs\ServiceInvoicePkg\ServiceInvoiceItem', 'service_invoice_id', 'id');
    }

    public function attachments()
    {
        return $this->hasMany('App\Attachment', 'entity_id', 'id')->where('attachment_of_id', 221)->where('attachment_type_id', 241);
    }

    public static function createFromCollection($records, $company = null)
    {
        foreach ($records as $key => $record_data) {
            try {
                if (!$record_data->company) {
                    continue;
                }
                $record = self::createFromObject($record_data, $company);
            } catch (Exception $e) {
                dd($e);
            }
        }
    }

    public function outlets()
    {
        return $this->belongsTo('App\Outlet', 'branch_id', 'id');
    }

    public function sbus()
    {
        return $this->belongsTo('App\Sbu', 'sbu_id', 'id');
    }

    public function outlet()
    {
        return $this->belongsTo('App\Outlet', 'branch_id', 'id');
    }

    public function company()
    {
        return $this->belongsTo('App\Company', 'company_id', 'id');
    }

    public static function createFromObject($record_data, $company = null)
    {
        if (!$company) {
            $company = Company::where('code', $record_data->company)->first();
        }
        $admin = $company->admin();

        $errors = [];
        if (!$company) {
            $errors[] = 'Invalid Company : ' . $record_data->company;
        }

        $outlet = Outlet::where('code', $record_data->outlet_code)->where('company_id', $company->id)->first();
        if (!$outlet) {
            $errors[] = 'Invalid outlet : ' . $record_data->outlet_code;
        }

        $sbu = Sbu::where('name', $record_data->sbu)->where('company_id', $company->id)->first();
        if (!$sbu) {
            $errors[] = 'Invalid sbu : ' . $record_data->sbu;
        }

        $sub_category = ServiceItemSubCategory::where('name', $record_data->sub_category)->where('company_id', $company->id)->first();
        if (!$sub_category) {
            $errors[] = 'Invalid sub_category : ' . $record_data->sub_category;
        }

        $customer = Customer::where('code', $record_data->customer)->where('company_id', $company->id)->first();
        if (!$customer) {
            $errors[] = 'Invalid customer : ' . $record_data->customer;
        }

        if (count($errors) > 0) {
            dump($errors);
            return;
        }

        $record = self::firstOrNew([
            'company_id' => $company->id,
            'number' => $record_data->service_invoice_number,
        ]);
        $record->branch_id = $outlet->id;
        $record->sbu_id = $sbu->id;
        $record->sub_category_id = $sub_category->id;
        $record->invoice_date = date('Y-m-d H:i:s', strtotime($record_data->invoice_date));
        $record->document_date = date('Y-m-d H:i:s', strtotime($record_data->document_date));
        $record->customer_id = $customer->id;
        $record->items_count = 0;
        $record->amount_total = 0;
        $record->tax_total = 0;
        $record->sub_total = 0;
        $record->total = 0;
        $record->created_by_id = $admin->id;
        $record->save();
        return $record;
    }

    public function exportToAxapta($delete = false)
    {
        $this->lineNumber = 1;
        // DB::beginTransaction();
        $axaptaExports = AxaptaExport::where([
            'DocumentNum' => $this->number,
            'company_id' => $this->company_id,
        ])->get();
        if (count($axaptaExports) > 0) {
            $errors[] = 'Already approved and exported to AX staging table';
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        //if ($delete) {
        //    AxaptaExport::where([
        //        'company_id' => $this->company_id,
        //        'entity_type_id' => 1400,
        //        'entity_id' => $this->id,
        //    ])->delete();
        //}
        // try {
        $item_codes = [];
        $total_amount_with_gst['debit'] = 0;
        $total_amount_with_gst['credit'] = 0;
        $total_amount_with_gst['invoice'] = 0;

        $total_amount_with_gst_not_kfc['debit'] = 0;
        $total_amount_with_gst_not_kfc['credit'] = 0;
        $total_amount_with_gst_not_kfc['invoice'] = 0;

        $tcs_calc_gst['debit'] = 0;
        $tcs_calc_gst['credit'] = 0;
        $tcs_calc_gst['invoice'] = 0;
        $KFC_IN = 0;
        //FOR TCS
        $tcs_total['credit'] = 0;
        $tcs_total['debit'] = 0;
        $tcs_total['invoice'] = 0;
        //FOR CESS on GST TAX
        $cess_on_gst_total['credit'] = 0;
        $cess_on_gst_total['debit'] = 0;
        $cess_on_gst_total['invoice'] = 0;
        // FOR KFC
        $kfc['credit'] = 0;
        $kfc['debit'] = 0;
        $kfc['invoice'] = 0;

        $cgst_amt['credit'] = 0;
        $cgst_amt['debit'] = 0;
        $cgst_amt['invoice'] = 0;

        $sgst_amt['credit'] = 0;
        $sgst_amt['debit'] = 0;
        $sgst_amt['invoice'] = 0;

        $igst_amt['credit'] = 0;
        $igst_amt['debit'] = 0;
        $igst_amt['invoice'] = 0;

        $kfc_amt['credit'] = 0;
        $kfc_amt['debit'] = 0;
        $kfc_amt['invoice'] = 0;

        $errors = [];
        $item_descriptions = [];
        foreach ($this->serviceInvoiceItems as $invoice_item) {
            $service_invoice = $invoice_item->serviceInvoice()->with([
                'toAccountType',
                // 'customer',
                // 'customer.primaryAddress',
                'branch',
                'branch.primaryAddress',
            ])
                ->first();

            $service_invoice->customer;
            $service_invoice->address;

            $date1 = Carbon::createFromFormat('d-m-Y', '31-07-2021');
            $date2 = Carbon::createFromFormat('d-m-Y', date('d-m-Y', strtotime($service_invoice->document_date)));
            $kfc_result = $date1->gte($date2);

            if (empty($service_invoice->branch->primaryAddress)) {
                $errors[] = 'Branch Primary Address Not Found! : ' . $service_invoice->branch->name;
                continue;
            }
            // dd($service_invoice->address);
            // $service_invoice->customer->primaryAddress;
            $invoice_cgst_percentage = 0;
            $invoice_sgst_percentage = 0;
            $invoice_igst_percentage = 0;
            $invoice_kfc_percentage = 0;
            $tcs_percentage = 0;
            foreach ($invoice_item->taxes as $invoice_tax) {
                // dump($invoice_tax);
                if ($invoice_tax->name == 'CGST') {
                    $invoice_cgst_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'SGST') {
                    $invoice_sgst_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'IGST') {
                    $invoice_igst_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'KFC') {
                    $invoice_kfc_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'TCS') {
                    $tcs_percentage = $invoice_tax->pivot->percentage;
                }
            }
            if (!empty($service_invoice)) {
                if ($service_invoice->address->state_id) {
                    if ($service_invoice->address->state_id == 3 && $service_invoice->branch->primaryAddress->state_id == 3 && empty($service_invoice->address->gst_number) && $service_invoice->type_id != 1060 && $kfc_result) {
                        // if (empty($service_invoice->address->gst_number)) {
                        // if ($service_invoice->type_id != 1060) {
                        if (!empty($invoice_item->serviceItem->taxCode)) {
                            $KFC_IN = 1;
                            foreach ($invoice_item->serviceItem->taxCode->taxes as $tax) {
                                if ($tax->name == 'CGST') {
                                    $total_amount_with_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                }
                                //FOR CGST
                                if ($tax->name == 'SGST') {
                                    $total_amount_with_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                }
                            }
                            //FOR KFC
                            if ($invoice_item->serviceItem->taxCode) {
                                $kfc_amt['credit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $kfc_amt['debit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $kfc_amt['invoice'] = $this->type_id == 1062 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $kfc['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $kfc['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $kfc['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $total_amount_with_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;

                                $total_amount_with_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;

                                $total_amount_with_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                            }
                        }
                        // }
                        // }
                    } else {
                        if (!empty($invoice_item->serviceItem->taxCode)) {
                            foreach ($invoice_item->serviceItem->taxCode->taxes as $tax) {
                                if ($tax->name == 'CGST' && $invoice_cgst_percentage != 0.00) {
                                    $total_amount_with_gst_not_kfc['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst_not_kfc['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst_not_kfc['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                }
                                //FOR SGST
                                if ($tax->name == 'SGST' && $invoice_sgst_percentage != 0.00) {
                                    $total_amount_with_gst_not_kfc['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst_not_kfc['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst_not_kfc['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                }

                                //FOR IGST
                                if ($tax->name == 'IGST' && $invoice_igst_percentage != 0.00) {
                                    $total_amount_with_gst_not_kfc['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst_not_kfc['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst_not_kfc['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                }
                            }
                        }
                    }
                }

                // dump($total_amount_with_gst['credit'], $total_amount_with_gst['debit'], $total_amount_with_gst['invoice']);
                // dump($total_amount_with_gst_not_kfc['credit'], $total_amount_with_gst_not_kfc['debit'], $total_amount_with_gst_not_kfc['invoice']);
                // dd(1);
                // dump($invoice_cgst_percentage, $invoice_sgst_percentage, $invoice_igst_percentage);
                // dump($invoice_item->sub_total);
                // dump($invoice_kfc_percentage);
                // dd($invoice_item->serviceItem->taxCode->taxes);
                // dump($invoice_kfc_percentage);
                if (!empty($invoice_item->serviceItem->taxCode)) {
                    foreach ($invoice_item->serviceItem->taxCode->taxes as $key => $tax) {
                        // dump($tax->name);
                        if ($tax->name == 'CGST' && $invoice_cgst_percentage != 0.00) {
                            $cgst_amt['credit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $cgst_amt['debit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $cgst_amt['invoice'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $tcs_calc_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                            $tcs_calc_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                            $tcs_calc_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                        }
                        //FOR CGST
                        if ($tax->name == 'SGST' && $invoice_sgst_percentage != 0.00) {
                            $sgst_amt['credit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $sgst_amt['debit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $sgst_amt['invoice'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $tcs_calc_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                            $tcs_calc_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                            $tcs_calc_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                        }
                        //FOR CGST
                        if ($tax->name == 'IGST' && $invoice_igst_percentage != 0.00) {
                            $igst_amt['credit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $igst_amt['debit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $igst_amt['invoice'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $tcs_calc_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                            $tcs_calc_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                            $tcs_calc_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                        }
                    }
                }
                // dump($tcs_calc_gst['credit'], $tcs_calc_gst['debit'], $tcs_calc_gst['invoice'], $invoice_item->sub_total);
                // dump($kfc['credit'], $kfc['debit'], $kfc['invoice'], $invoice_item->sub_total);
                // dump($kfc['invoice'], $tcs_calc_gst['invoice'], $invoice_item->sub_total);
                // if ($invoice_item->serviceItem->tcs_percentage && $invoice_item->serviceItem->is_tcs == 1) {
                if ($invoice_item->serviceItem->tcs_percentage) {
                    // $document_date = (string) $service_invoice->document_date;
                    // $date1 = Carbon::createFromFormat('d-m-Y', '31-03-2021');
                    // $date2 = Carbon::createFromFormat('d-m-Y', $document_date);
                    // $result = $date1->gte($date2);

                    // $tcs_limit = Entity::where('entity_type_id', 38)->where('company_id', Auth::user()->company_id)->pluck('name')->first();
                    // $tcs_percentage = 0;
                    // if($invoice_item->sub_total >= $tcs_limit) {
                    //     $tcs_percentage = $invoice_item->serviceItem->tcs_percentage;
                    //     if (!$result) {
                    //         $tcs_percentage = 1;
                    //     }
                    // }

                    // $tcs_total['credit'] += $this->type_id == 1060 ? round(($kfc_amt['credit'] + $igst_amt['credit'] + $sgst_amt['credit'] + $cgst_amt['credit'] + $invoice_item->sub_total) * $invoice_item->serviceItem->tcs_percentage / 100, 2) : 0;
                    // $tcs_total['debit'] += $this->type_id == 1061 ? round(($kfc_amt['debit'] + $igst_amt['debit'] + $sgst_amt['debit'] + $cgst_amt['debit'] + $invoice_item->sub_total) * $invoice_item->serviceItem->tcs_percentage / 100, 2) : 0;
                    // $tcs_total['invoice'] += $this->type_id == 1062 ? round(($kfc_amt['invoice'] + $igst_amt['invoice'] + $sgst_amt['invoice'] + $cgst_amt['invoice'] + $invoice_item->sub_total) * $invoice_item->serviceItem->tcs_percentage / 100, 2) : 0;
                    $tcs_total['credit'] += $this->type_id == 1060 ? round(($kfc_amt['credit'] + $igst_amt['credit'] + $sgst_amt['credit'] + $cgst_amt['credit'] + $invoice_item->sub_total) * $tcs_percentage / 100, 2) : 0;
                    $tcs_total['debit'] += $this->type_id == 1061 ? round(($kfc_amt['debit'] + $igst_amt['debit'] + $sgst_amt['debit'] + $cgst_amt['debit'] + $invoice_item->sub_total) * $tcs_percentage / 100, 2) : 0;
                    $tcs_total['invoice'] += $this->type_id == 1062 ? round(($kfc_amt['invoice'] + $igst_amt['invoice'] + $sgst_amt['invoice'] + $cgst_amt['invoice'] + $invoice_item->sub_total) * $tcs_percentage / 100, 2) : 0;

                }
                //ONLY APPLICABLE FOR KL OUTLETS
                if ($invoice_item->serviceItem->cess_on_gst_percentage) {
                    $cess_on_gst_total['credit'] += $this->type_id == 1060 ? round(($invoice_item->sub_total) * $invoice_item->serviceItem->cess_on_gst_percentage / 100, 2) : 0;
                    $cess_on_gst_total['debit'] += $this->type_id == 1061 ? round(($invoice_item->sub_total) * $invoice_item->serviceItem->cess_on_gst_percentage / 100, 2) : 0;

                    $cess_on_gst_total['invoice'] += $this->type_id == 1062 ? round(($invoice_item->sub_total) * $invoice_item->serviceItem->cess_on_gst_percentage / 100, 2) : 0;
                }
            }
            $item_codes[] = $invoice_item->serviceItem->code;
            $item_descriptions[] = $invoice_item->description;
        }
        // dump($tcs_total['invoice'], $tcs_total['credit'], $tcs_total['debit'], $this->serviceInvoiceItems()->sum('sub_total'));
        // dump($cess_on_gst_total['invoice'], $cess_on_gst_total['credit'], $cess_on_gst_total['debit'], $this->serviceInvoiceItems()->sum('sub_total'));
        // dd($this->branch->primaryAddress->state->cess_on_gst_coa_code);
        // dd(1);
        // dump($total_amount_with_gst['invoice'], $total_amount_with_gst['credit'], $total_amount_with_gst['debit']);
        $Txt = implode(',', $item_descriptions);
        if ($this->type_id == 1060) {
            //CN
            $Txt .= ' - Credit note for ';
        } elseif ($this->type_id == 1061) {
            //DN
            $Txt .= ' - Debit note for ';
        } elseif ($this->type_id == 1062) {
            //INV
            $Txt .= ' - Invoice for ';
        }
        $Txt .= implode(',', $item_codes);

        // dump($Txt);
        // dump($this->serviceInvoiceItems()->sum('sub_total'));
        $amount_diff = 0;
        if (!empty($this->final_amount) && !empty($this->total)) {
            $amount_diff = number_format(($this->final_amount - $this->total), 2);
        }

        // dump($amount_diff);

        if ($total_amount_with_gst['debit'] == 0 && $total_amount_with_gst['credit'] == 0 && $total_amount_with_gst['invoice'] == 0) {
            // dump('if');
            $params = [
                'Voucher' => 'V',
                'AccountType' => $this->to_account_type_id == 1440 ? 'Customer' : 'Vendor',
                'LedgerDimension' => $this->customer->code,
                'Txt' => $Txt . '-' . $this->number,
                // 'AmountCurDebit' => ($this->type_id == 1061 || $this->type_id == 1062) ? $this->serviceInvoiceItems()->sum('sub_total') : 0,
                'TaxGroup' => '',
                'LineNum' => 1,
            ];
            //ADDED FOR ROUND OFF
            if ($amount_diff > 0) {
                // dump('if');
                $params['AmountCurCredit'] = $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst_not_kfc['credit'] + $tcs_total['credit'] + $cess_on_gst_total['credit'] : 0;
                if ($this->type_id == 1061) {
                    $params['AmountCurDebit'] = $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst_not_kfc['debit'] + $tcs_total['debit'] + $cess_on_gst_total['debit'] : 0;

                } elseif ($this->type_id == 1062) {
                    $params['AmountCurDebit'] = $this->type_id == 1062 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst_not_kfc['invoice'] + $tcs_total['invoice'] + $cess_on_gst_total['invoice'] : 0;
                } else {
                    $params['AmountCurDebit'] = 0;
                }
            } else {
                // dump('else');
                $params['AmountCurCredit'] = $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst_not_kfc['credit'] + $tcs_total['credit'] + $cess_on_gst_total['credit'] : 0;
                if ($this->type_id == 1061) {

                    $params['AmountCurDebit'] = $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst_not_kfc['debit'] + $tcs_total['debit'] + $cess_on_gst_total['debit'] : 0;

                } elseif ($this->type_id == 1062) {

                    $params['AmountCurDebit'] = $this->type_id == 1062 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst_not_kfc['invoice'] + $tcs_total['invoice'] + $cess_on_gst_total['invoice'] : 0;
                } else {
                    $params['AmountCurDebit'] = 0;
                }
            }
        } else {
            // dump('else');
            $params = [
                'Voucher' => 'V',
                'AccountType' => $this->to_account_type_id == 1440 ? 'Customer' : 'Vendor',
                'LedgerDimension' => $this->customer->code,
                'Txt' => $Txt . '-' . $this->number,
                // 'AmountCurDebit' => $this->type_id == 1061 ? ($total_amount_with_gst['debit'] + ($this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') : 0)) : 0,
                'TaxGroup' => '',
                'LineNum' => 1,
            ];
            //ADDED FOR ROUND OFF
            if ($amount_diff > 0) {
                // dump('if');
                if ($this->type_id == 1061) {
                    $params['AmountCurDebit'] = $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst['debit'] + $tcs_total['debit'] + $cess_on_gst_total['debit'] : 0;
                } elseif ($this->type_id == 1062) {
                    $params['AmountCurDebit'] = $this->type_id == 1062 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst['invoice'] + $tcs_total['invoice'] + $cess_on_gst_total['invoice'] : 0;
                } else {
                    $params['AmountCurDebit'] = 0;
                }
                $params['AmountCurCredit'] = $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst['credit'] + $tcs_total['credit'] + $cess_on_gst_total['credit'] : 0;
            } else {
                // dump('else');
                if ($this->type_id == 1061) {
                    $params['AmountCurDebit'] = $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst['debit'] + $tcs_total['debit'] + $cess_on_gst_total['debit'] : 0;

                } elseif ($this->type_id == 1062) {
                    $params['AmountCurDebit'] = $this->type_id == 1062 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst['invoice'] + $tcs_total['invoice'] + $cess_on_gst_total['invoice'] : 0;
                } else {
                    $params['AmountCurDebit'] = 0;
                }
                $params['AmountCurCredit'] = $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst['credit'] + $tcs_total['credit'] + $cess_on_gst_total['credit'] : 0;
            }
        }

        if ($this->serviceInvoiceItems[0]->taxCode) {
            if ($this->serviceInvoiceItems[0]->taxCode->type_id == 1020) {
                //HSN Code
                $params['TVSHSNCode'] = $this->serviceInvoiceItems[0]->taxCode->code;
                $params['TVSSACCode'] = '';
            } else {
                $params['TVSHSNCode'] = '';
                $params['TVSSACCode'] = $this->serviceInvoiceItems[0]->taxCode->code;
            }
        } else {
            $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
        }
        // dump($params);
        // dd(1);

        $this->exportRowToAxapta($params);

        foreach ($this->serviceInvoiceItems as $invoice_item) {
            if (!$invoice_item->serviceItem->coaCode) {
                $errors[] = 'COA Code not configured. Item Code : ' . $invoice_item->serviceItem->code;
                continue;
            }
        }
        if (count($errors) > 0) {
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        $line_number = 1;
        foreach ($this->serviceInvoiceItems as $invoice_item) {
            $params = [
                'Voucher' => 'D',
                'AccountType' => 'Ledger',
                'LedgerDimension' => $invoice_item->serviceItem->coaCode->code . '-' . $this->branch->code . '-' . $this->sbu->name,
                'Txt' => $invoice_item->serviceItem->code . ' ' . $invoice_item->serviceItem->description . ' ' . $invoice_item->description . '-' . $this->number . '-' . $this->customer->code,
                'AmountCurDebit' => $this->type_id == 1060 ? $invoice_item->sub_total : 0,
                // 'AmountCurCredit' => $this->type_id == 1061 ? $invoice_item->sub_total : 0,
                'TaxGroup' => '',
                'LineNum' => ++$line_number,
                // 'TVSSACCode' => ($invoice_item->serviceItem->taxCode != null) ? $invoice_item->serviceItem->taxCode->code : NULL,
            ];
            if ($this->type_id == 1061) {
                $params['AmountCurCredit'] = $this->type_id == 1061 ? $invoice_item->sub_total : 0;
            } elseif ($this->type_id == 1062) {
                $params['AmountCurCredit'] = $this->type_id == 1062 ? $invoice_item->sub_total : 0;
            } else {
                $params['AmountCurCredit'] = 0;
            }

            if ($invoice_item->serviceItem->taxCode && $KFC_IN == 0) {
                if ($invoice_item->serviceItem->taxCode->type_id == 1020) {
                    //HSN Code
                    $params['TVSHSNCode'] = $invoice_item->serviceItem->taxCode->code;
                    $params['TVSSACCode'] = '';
                } else {
                    $params['TVSHSNCode'] = '';
                    $params['TVSSACCode'] = $invoice_item->serviceItem->taxCode->code;
                }
            } else {
                $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
            }
            // dump($params);
            $this->exportRowToAxapta($params);

            $service_invoice = $invoice_item->serviceInvoice()->with([
                'toAccountType',
                // 'customer',
                // 'customer.primaryAddress',
                'branch',
                'branch.primaryAddress',
            ])
                ->first();

            $date1 = Carbon::createFromFormat('d-m-Y', '31-07-2021');
            $date2 = Carbon::createFromFormat('d-m-Y', date('d-m-Y', strtotime($service_invoice->document_date)));
            $kfc_result = $date1->gte($date2);

            $service_invoice->address;

            foreach ($invoice_item->taxes as $invoice_tax) {
                // dump($invoice_tax);
                if ($invoice_tax->name == 'CGST') {
                    $invoice_cgst_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'SGST') {
                    $invoice_sgst_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'IGST') {
                    $invoice_igst_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'KFC') {
                    $invoice_kfc_percentage = $invoice_tax->pivot->percentage;
                }
            }
            // $service_invoice->customer->primaryAddress;
            // dump($service_invoice);
            // dd(1);
            if (!empty($service_invoice)) {
                if ($service_invoice->address->state_id) {
                    if ($service_invoice->address->state_id == 3 && $service_invoice->branch->primaryAddress->state_id == 3 && empty($service_invoice->address->gst_number) && $service_invoice->type_id != 1060 && $kfc_result) {
                        // if ($service_invoice->type_id != 1060) {
                        // if (empty($service_invoice->address->gst_number)) {
                        //FOR AXAPTA EXPORT WHILE GETING KFC ADD SEPERATE TAX LIKE CGST,SGST
                        if (!empty($invoice_item->serviceItem->taxCode)) {
                            foreach ($invoice_item->serviceItem->taxCode->taxes as $tax) {
                                //FOR CGST
                                if ($tax->name == 'CGST') {
                                    if ($this->type_id == 1061) {
                                        $params['AmountCurCredit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } elseif ($this->type_id == 1062) {
                                        $params['AmountCurCredit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } else {
                                        $params['AmountCurCredit'] = 0;
                                    }

                                    $params['AmountCurDebit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    $params['LedgerDimension'] = '7132' . '-' . $this->branch->code . '-' . $this->sbu->name;

                                    $params['LineNum'] = ++$line_number;
                                    // dump($params['LineNum']);
                                    $line_number = $params['LineNum'];

                                    //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
                                    $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
                                    // dump($params);
                                    $this->exportRowToAxapta($params);
                                }
                                //FOR CGST
                                if ($tax->name == 'SGST') {
                                    if ($this->type_id == 1061) {
                                        $params['AmountCurCredit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } elseif ($this->type_id == 1062) {
                                        $params['AmountCurCredit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } else {
                                        $params['AmountCurCredit'] = 0;
                                    }

                                    $params['AmountCurDebit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    $params['LedgerDimension'] = '7432' . '-' . $this->branch->code . '-' . $this->sbu->name;

                                    //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
                                    $params['TVSHSNCode'] = $params['TVSSACCode'] = null;

                                    // dump($params);
                                    $this->exportRowToAxapta($params);
                                }
                            }
                            //FOR KFC
                            if ($invoice_item->serviceItem->taxCode) {
                                if ($this->type_id == 1061) {
                                    $params['AmountCurCredit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                } elseif ($this->type_id == 1062) {
                                    $params['AmountCurCredit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                } else {
                                    $params['AmountCurCredit'] = 0;
                                }
                                $params['AmountCurDebit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $params['LedgerDimension'] = '2230' . '-' . $this->branch->code . '-' . $this->sbu->name;

                                $params['LineNum'] = ++$line_number;
                                // dump($params['LineNum']);
                                $line_number = $params['LineNum'];

                                //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
                                $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
                                // dump($params);
                                $this->exportRowToAxapta($params);
                            }

                        }
                        // }
                        // }
                    } else {
                        if (!empty($invoice_item->serviceItem->taxCode)) {
                            foreach ($invoice_item->serviceItem->taxCode->taxes as $tax) {
                                //FOR CGST
                                if ($tax->name == 'CGST' && $invoice_cgst_percentage != 0.00) {
                                    if ($this->type_id == 1061) {
                                        $params['AmountCurCredit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } elseif ($this->type_id == 1062) {
                                        $params['AmountCurCredit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } else {
                                        $params['AmountCurCredit'] = 0;
                                    }

                                    $params['AmountCurDebit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    $params['LedgerDimension'] = $this->branch->primaryAddress->state->cgst_coa_code . '-' . $this->branch->code . '-' . $this->sbu->name;

                                    $params['LineNum'] = ++$line_number;
                                    // dump($params['LineNum']);
                                    $line_number = $params['LineNum'];

                                    //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
                                    $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
                                    // dump($params);
                                    $this->exportRowToAxapta($params);
                                }
                                //FOR CGST
                                if ($tax->name == 'SGST' && $invoice_sgst_percentage != 0.00) {
                                    if ($this->type_id == 1061) {
                                        $params['AmountCurCredit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } elseif ($this->type_id == 1062) {
                                        $params['AmountCurCredit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } else {
                                        $params['AmountCurCredit'] = 0;
                                    }

                                    $params['AmountCurDebit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    $params['LedgerDimension'] = $this->branch->primaryAddress->state->sgst_coa_code . '-' . $this->branch->code . '-' . $this->sbu->name;

                                    $params['LineNum'] = ++$line_number;
                                    // dump($params['LineNum']);
                                    $line_number = $params['LineNum'];

                                    //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
                                    $params['TVSHSNCode'] = $params['TVSSACCode'] = null;

                                    // dump($params);
                                    $this->exportRowToAxapta($params);
                                }
                                //FOR IGST
                                if ($tax->name == 'IGST' && $invoice_igst_percentage != 0.00) {
                                    if ($this->type_id == 1061) {
                                        $params['AmountCurCredit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } elseif ($this->type_id == 1062) {
                                        $params['AmountCurCredit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } else {
                                        $params['AmountCurCredit'] = 0;
                                    }

                                    $params['AmountCurDebit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    $params['LedgerDimension'] = $this->branch->primaryAddress->state->igst_coa_code . '-' . $this->branch->code . '-' . $this->sbu->name;

                                    $params['LineNum'] = ++$line_number;
                                    // dump($params['LineNum']);
                                    $line_number = $params['LineNum'];

                                    //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
                                    $params['TVSHSNCode'] = $params['TVSSACCode'] = null;

                                    // dump($params);
                                    $this->exportRowToAxapta($params);
                                }
                            }
                        }
                    }
                }
            }

        }
        // dd($tcs_total['invoice']);
        //FOR TCS TAX
        // dump($tcs_total['invoice'], $tcs_total['credit'], $tcs_total['debit']);
        if ($tcs_total['invoice'] != 0 || $tcs_total['credit'] != 0 || $tcs_total['debit'] != 0) {
            // dd('in');
            if ($this->type_id == 1061) {
                $params['AmountCurCredit'] = $this->type_id == 1061 ? round($tcs_total['debit'], 2) : 0;
            } elseif ($this->type_id == 1062) {
                $params['AmountCurCredit'] = $this->type_id == 1062 ? round($tcs_total['invoice'], 2) : 0;
            } else {
                $params['AmountCurCredit'] = 0;
            }
            $params['AmountCurDebit'] = $this->type_id == 1060 ? round($tcs_total['credit'], 2) : 0;
            $params['LedgerDimension'] = '2269' . '-' . $this->branch->code . '-' . $this->sbu->name;

            $params['LineNum'] = ++$line_number;
            // dump($params['LineNum']);
            $line_number = $params['LineNum'];

            //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
            $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
            // dump($params);
            $this->exportRowToAxapta($params);
        }

        //FOR CESS on GST TAX
        // dump($cess_on_gst_total['invoice'], $cess_on_gst_total['credit'], $cess_on_gst_total['debit']);
        if ($cess_on_gst_total['invoice'] != 0 || $cess_on_gst_total['credit'] != 0 || $cess_on_gst_total['debit'] != 0) {
            // dd('in');
            if ($this->type_id == 1061) {
                $params['AmountCurCredit'] = $this->type_id == 1061 ? round($cess_on_gst_total['debit'], 2) : 0;
            } elseif ($this->type_id == 1062) {
                $params['AmountCurCredit'] = $this->type_id == 1062 ? round($cess_on_gst_total['invoice'], 2) : 0;
            } else {
                $params['AmountCurCredit'] = 0;
            }
            $params['AmountCurDebit'] = $this->type_id == 1060 ? round($cess_on_gst_total['credit'], 2) : 0;
            $params['LedgerDimension'] = $this->branch->primaryAddress->state->cess_on_gst_coa_code . '-' . $this->branch->code . '-' . $this->sbu->name;

            $params['LineNum'] = ++$line_number;
            // dump($params['LineNum']);
            $line_number = $params['LineNum'];
            // dd($params);
            //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
            $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
            // dump($params);
            $this->exportRowToAxapta($params);
        }

        if (!empty($service_invoice->round_off_amount) && $service_invoice->round_off_amount != '0.00') {
            if ($amount_diff > 0) {
                if ($this->type_id == 1061) {
                    $params['AmountCurCredit'] = $this->type_id == 1061 ? $amount_diff : 0;
                } elseif ($this->type_id == 1062) {
                    $params['AmountCurCredit'] = $this->type_id == 1062 ? $amount_diff : 0;
                } else {
                    $params['AmountCurCredit'] = 0;
                }
                $params['AmountCurDebit'] = $this->type_id == 1060 ? $amount_diff : 0;
                $params['LedgerDimension'] = '3198' . '-' . $this->branch->code . '-' . $this->sbu->name;

                $params['LineNum'] = ++$line_number;
                // dump($params['LineNum']);
                $line_number = $params['LineNum'];

                // dump('if');
                // dd($params);
                $this->exportRowToAxapta($params);
            } else {
                if ($this->type_id == 1061) {
                    $params['AmountCurDebit'] = $this->type_id == 1061 ? ($amount_diff > 0 ? $amount_diff : $amount_diff * -1) : 0;
                } elseif ($this->type_id == 1062) {
                    $params['AmountCurDebit'] = $this->type_id == 1062 ? ($amount_diff > 0 ? $amount_diff : $amount_diff * -1) : 0;
                } else {
                    $params['AmountCurDebit'] = 0;
                }
                $params['AmountCurCredit'] = $this->type_id == 1060 ? ($amount_diff > 0 ? $amount_diff : $amount_diff * -1) : 0;
                $params['LedgerDimension'] = '3198' . '-' . $this->branch->code . '-' . $this->sbu->name;

                $params['LineNum'] = ++$line_number;
                // dump($params['LineNum']);
                $line_number = $params['LineNum'];
                // dump('else');
                // dd($params);
                $this->exportRowToAxapta($params);
            }
        }

        return [
            'success' => true,
        ];

        //     DB::commit();
        //     // dd(1);
        // } catch (\Exception $e) {
        //     DB::rollback();
        //     dd($e);
        // }
    }

    public function exportToAxaptaCancel()
    {
        $this->lineNumber = 1;
        $item_codes = [];

        $total_amount_with_gst['debit'] = 0;
        $total_amount_with_gst['credit'] = 0;
        $total_amount_with_gst['invoice'] = 0;

        $total_amount_with_gst_not_kfc['debit'] = 0;
        $total_amount_with_gst_not_kfc['credit'] = 0;
        $total_amount_with_gst_not_kfc['invoice'] = 0;

        $tcs_calc_gst['debit'] = 0;
        $tcs_calc_gst['credit'] = 0;
        $tcs_calc_gst['invoice'] = 0;
        $KFC_IN = 0;
        //FOR TCS
        $tcs_total['credit'] = 0;
        $tcs_total['debit'] = 0;
        $tcs_total['invoice'] = 0;
        //FOR CESS on GST TAX
        $cess_on_gst_total['credit'] = 0;
        $cess_on_gst_total['debit'] = 0;
        $cess_on_gst_total['invoice'] = 0;
        // FOR KFC
        $kfc['credit'] = 0;
        $kfc['debit'] = 0;
        $kfc['invoice'] = 0;

        $cgst_amt['credit'] = 0;
        $cgst_amt['debit'] = 0;
        $cgst_amt['invoice'] = 0;

        $sgst_amt['credit'] = 0;
        $sgst_amt['debit'] = 0;
        $sgst_amt['invoice'] = 0;

        $igst_amt['credit'] = 0;
        $igst_amt['debit'] = 0;
        $igst_amt['invoice'] = 0;

        $kfc_amt['credit'] = 0;
        $kfc_amt['debit'] = 0;
        $kfc_amt['invoice'] = 0;

        $errors = [];
        foreach ($this->serviceInvoiceItems as $invoice_item) {
            $service_invoice = $invoice_item->serviceInvoice()->with([
                'toAccountType',
                // 'customer',
                // 'customer.primaryAddress',
                'branch',
                'branch.primaryAddress',
            ])
                ->first();

            $service_invoice->customer;
            $service_invoice->address;

            $date1 = Carbon::createFromFormat('d-m-Y', '31-07-2021');
            $date2 = Carbon::createFromFormat('d-m-Y', date('d-m-Y', strtotime($service_invoice->document_date)));
            $kfc_result = $date1->gte($date2);

            if (empty($service_invoice->branch->primaryAddress)) {
                $errors[] = 'Branch Primary Address Not Found! : ' . $service_invoice->branch->name;
                continue;
            }
            // dd($service_invoice->address);
            // $service_invoice->customer->primaryAddress;
            $invoice_cgst_percentage = 0;
            $invoice_sgst_percentage = 0;
            $invoice_igst_percentage = 0;
            $invoice_kfc_percentage = 0;
            $tcs_percentage = 0;
            foreach ($invoice_item->taxes as $invoice_tax) {
                // dump($invoice_tax);
                if ($invoice_tax->name == 'CGST') {
                    $invoice_cgst_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'SGST') {
                    $invoice_sgst_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'IGST') {
                    $invoice_igst_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'KFC') {
                    $invoice_kfc_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'TCS') {
                    $tcs_percentage = $invoice_tax->pivot->percentage;
                }
            }

            if (!empty($service_invoice)) {

                if ($service_invoice->address->state_id) {
                    if ($service_invoice->address->state_id == 3 && $service_invoice->branch->primaryAddress->state_id == 3 && empty($service_invoice->address->gst_number) && $service_invoice->type_id != 1060 && $kfc_result) {
                        // if ($service_invoice->type_id != 1060) {
                        // if (empty($service_invoice->address->gst_number)) {
                        if (!empty($invoice_item->serviceItem->taxCode)) {
                            $KFC_IN = 1;
                            foreach ($invoice_item->serviceItem->taxCode->taxes as $tax) {
                                if ($tax->name == 'CGST') {
                                    $total_amount_with_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                }
                                //FOR CGST
                                if ($tax->name == 'SGST') {
                                    $total_amount_with_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                }
                            }
                            //FOR KFC
                            if ($invoice_item->serviceItem->taxCode) {
                                $kfc_amt['credit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $kfc_amt['debit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $kfc_amt['invoice'] = $this->type_id == 1062 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $kfc['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $kfc['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $kfc['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $total_amount_with_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;

                                $total_amount_with_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;

                                $total_amount_with_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                            }
                        }
                        // }
                        // }
                    } else {
                        if (!empty($invoice_item->serviceItem->taxCode)) {
                            foreach ($invoice_item->serviceItem->taxCode->taxes as $tax) {
                                if ($tax->name == 'CGST' && $invoice_cgst_percentage != 0.00) {
                                    $total_amount_with_gst_not_kfc['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst_not_kfc['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst_not_kfc['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                }
                                //FOR SGST
                                if ($tax->name == 'SGST' && $invoice_sgst_percentage != 0.00) {
                                    $total_amount_with_gst_not_kfc['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst_not_kfc['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst_not_kfc['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                }

                                //FOR IGST
                                if ($tax->name == 'IGST' && $invoice_igst_percentage != 0.00) {
                                    $total_amount_with_gst_not_kfc['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst_not_kfc['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                                    $total_amount_with_gst_not_kfc['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                }
                            }
                        }
                    }
                }

                // dump($total_amount_with_gst['credit'], $total_amount_with_gst['debit'], $total_amount_with_gst['invoice']);
                // dump($invoice_cgst_percentage, $invoice_sgst_percentage, $invoice_igst_percentage);
                // dump($invoice_item->sub_total);
                // dump($invoice_kfc_percentage);
                // dd($invoice_item->serviceItem->taxCode->taxes);
                // dump($invoice_kfc_percentage);
                if (!empty($invoice_item->serviceItem->taxCode)) {
                    foreach ($invoice_item->serviceItem->taxCode->taxes as $key => $tax) {
                        // dump($tax->name);
                        if ($tax->name == 'CGST' && $invoice_cgst_percentage != 0.00) {
                            $cgst_amt['credit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $cgst_amt['debit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $cgst_amt['invoice'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $tcs_calc_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                            $tcs_calc_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                            $tcs_calc_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                        }
                        //FOR CGST
                        if ($tax->name == 'SGST' && $invoice_sgst_percentage != 0.00) {
                            $sgst_amt['credit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $sgst_amt['debit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $sgst_amt['invoice'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $tcs_calc_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                            $tcs_calc_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                            $tcs_calc_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                        }
                        //FOR CGST
                        if ($tax->name == 'IGST' && $invoice_igst_percentage != 0.00) {
                            $igst_amt['credit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $igst_amt['debit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $igst_amt['invoice'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                            $tcs_calc_gst['credit'] += $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                            $tcs_calc_gst['debit'] += $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;

                            $tcs_calc_gst['invoice'] += $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                        }
                    }
                }
                // dump($tcs_calc_gst['credit'], $tcs_calc_gst['debit'], $tcs_calc_gst['invoice'], $invoice_item->sub_total);
                // dump($kfc['credit'], $kfc['debit'], $kfc['invoice'], $invoice_item->sub_total);
                // dump($kfc['invoice'], $tcs_calc_gst['invoice'], $invoice_item->sub_total);
                // if ($invoice_item->serviceItem->tcs_percentage && $invoice_item->serviceItem->is_tcs == 1) {
                if ($invoice_item->serviceItem->tcs_percentage) {
                    $tcs_total['credit'] += $this->type_id == 1060 ? round(($kfc_amt['credit'] + $igst_amt['credit'] + $sgst_amt['credit'] + $cgst_amt['credit'] + $invoice_item->sub_total) * $tcs_percentage / 100, 2) : 0;
                    $tcs_total['debit'] += $this->type_id == 1061 ? round(($kfc_amt['debit'] + $igst_amt['debit'] + $sgst_amt['debit'] + $cgst_amt['debit'] + $invoice_item->sub_total) * $tcs_percentage / 100, 2) : 0;

                    $tcs_total['invoice'] += $this->type_id == 1062 ? round(($kfc_amt['invoice'] + $igst_amt['invoice'] + $sgst_amt['invoice'] + $cgst_amt['invoice'] + $invoice_item->sub_total) * $tcs_percentage / 100, 2) : 0;
                }

                //ONLY APPLICABLE FOR KL OUTLETS
                if ($invoice_item->serviceItem->cess_on_gst_percentage) {
                    $cess_on_gst_total['credit'] += $this->type_id == 1060 ? round(($invoice_item->sub_total) * $invoice_item->serviceItem->cess_on_gst_percentage / 100, 2) : 0;
                    $cess_on_gst_total['debit'] += $this->type_id == 1061 ? round(($invoice_item->sub_total) * $invoice_item->serviceItem->cess_on_gst_percentage / 100, 2) : 0;

                    $cess_on_gst_total['invoice'] += $this->type_id == 1062 ? round(($invoice_item->sub_total) * $invoice_item->serviceItem->cess_on_gst_percentage / 100, 2) : 0;
                }
            }
            $item_codes[] = $invoice_item->serviceItem->code;
            $item_descriptions[] = $invoice_item->description;
        }
        // dump($tcs_total['invoice'], $tcs_total['credit'], $tcs_total['debit'], $this->serviceInvoiceItems()->sum('sub_total'));
        // dd(1);
        // dump($total_amount_with_gst['invoice'], $total_amount_with_gst['credit'], $total_amount_with_gst['debit']);
        $Txt = implode(',', $item_descriptions);
        if ($this->type_id == 1060) {
            //CN
            $Txt .= ' - Credit note for ';
        } elseif ($this->type_id == 1061) {
            //DN
            $Txt .= ' - Debit note for ';
        } elseif ($this->type_id == 1062) {
            //INV
            $Txt .= ' - Invoice for ';
        }
        $Txt .= implode(',', $item_codes);

        // dump($Txt);
        // dump($this->serviceInvoiceItems()->sum('sub_total'));
        $amount_diff = 0;
        if (!empty($this->final_amount) && !empty($this->total)) {
            $amount_diff = number_format(($this->final_amount - $this->total), 2);
        }

        // dump($amount_diff);

        if ($total_amount_with_gst['debit'] == 0 && $total_amount_with_gst['credit'] == 0 && $total_amount_with_gst['invoice'] == 0) {
            // dump('if');
            $params = [
                'Voucher' => 'V',
                'AccountType' => $this->to_account_type_id == 1440 ? 'Customer' : 'Vendor',
                'LedgerDimension' => $this->customer->code,
                'Txt' => $Txt . '-' . $this->number,
                // 'AmountCurDebit' => ($this->type_id == 1061 || $this->type_id == 1062) ? $this->serviceInvoiceItems()->sum('sub_total') : 0,
                'TaxGroup' => '',
                'LineNum' => 1,
            ];
            //ADDED FOR ROUND OFF
            if ($amount_diff > 0) {
                // dump('if');
                $params['AmountCurDebit'] = $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst_not_kfc['credit'] + $tcs_total['credit'] + $cess_on_gst_total['credit'] : 0;
                if ($this->type_id == 1061) {
                    $params['AmountCurCredit'] = $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst_not_kfc['debit'] + $tcs_total['debit'] + $cess_on_gst_total['debit'] : 0;

                } elseif ($this->type_id == 1062) {
                    $params['AmountCurCredit'] = $this->type_id == 1062 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst_not_kfc['invoice'] + $tcs_total['invoice'] + $cess_on_gst_total['invoice'] : 0;
                } else {
                    $params['AmountCurCredit'] = 0;
                }
            } else {
                // dump('else');
                $params['AmountCurDebit'] = $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst_not_kfc['credit'] + $tcs_total['credit'] + $cess_on_gst_total['credit'] : 0;
                if ($this->type_id == 1061) {

                    $params['AmountCurCredit'] = $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst_not_kfc['debit'] + $tcs_total['debit'] + $cess_on_gst_total['debit'] : 0;

                } elseif ($this->type_id == 1062) {

                    $params['AmountCurCredit'] = $this->type_id == 1062 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst_not_kfc['invoice'] + $tcs_total['invoice'] + $cess_on_gst_total['invoice'] : 0;
                } else {
                    $params['AmountCurCredit'] = 0;
                }
            }
        } else {
            // dump('else');
            $params = [
                'Voucher' => 'V',
                'AccountType' => $this->to_account_type_id == 1440 ? 'Customer' : 'Vendor',
                'LedgerDimension' => $this->customer->code,
                'Txt' => $Txt . '-' . $this->number,
                // 'AmountCurDebit' => $this->type_id == 1061 ? ($total_amount_with_gst['debit'] + ($this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') : 0)) : 0,
                'TaxGroup' => '',
                'LineNum' => 1,
            ];
            //ADDED FOR ROUND OFF
            if ($amount_diff > 0) {
                // dump('if');
                if ($this->type_id == 1061) {
                    $params['AmountCurCredit'] = $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst['debit'] + $tcs_total['debit'] + $cess_on_gst_total['debit'] : 0;
                } elseif ($this->type_id == 1062) {
                    $params['AmountCurCredit'] = $this->type_id == 1062 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst['invoice'] + $tcs_total['invoice'] + $cess_on_gst_total['invoice'] : 0;
                } else {
                    $params['AmountCurCredit'] = 0;
                }
                $params['AmountCurDebit'] = $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst['credit'] + $tcs_total['credit'] + $cess_on_gst_total['credit'] : 0;
            } else {
                // dump('else');
                if ($this->type_id == 1061) {
                    $params['AmountCurCredit'] = $this->type_id == 1061 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst['debit'] + $tcs_total['debit'] + $cess_on_gst_total['debit'] : 0;

                } elseif ($this->type_id == 1062) {
                    $params['AmountCurCredit'] = $this->type_id == 1062 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst['invoice'] + $tcs_total['invoice'] + $cess_on_gst_total['invoice'] : 0;
                } else {
                    $params['AmountCurCredit'] = 0;
                }
                $params['AmountCurDebit'] = $this->type_id == 1060 ? $this->serviceInvoiceItems()->sum('sub_total') + $amount_diff + $total_amount_with_gst['credit'] + $tcs_total['credit'] + $cess_on_gst_total['credit'] : 0;
            }
        }

        if ($this->serviceInvoiceItems[0]->taxCode) {
            if ($this->serviceInvoiceItems[0]->taxCode->type_id == 1020) {
                //HSN Code
                $params['TVSHSNCode'] = $this->serviceInvoiceItems[0]->taxCode->code;
                $params['TVSSACCode'] = '';
            } else {
                $params['TVSHSNCode'] = '';
                $params['TVSSACCode'] = $this->serviceInvoiceItems[0]->taxCode->code;
            }
        } else {
            $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
        }
        // dump($params);
        // dd(1);

        $this->exportRowToAxapta($params);

        foreach ($this->serviceInvoiceItems as $invoice_item) {
            if (!$invoice_item->serviceItem->coaCode) {
                $errors[] = 'COA Code not configured. Item Code : ' . $invoice_item->serviceItem->code;
                continue;
            }
        }
        if (count($errors) > 0) {
            return [
                'success' => false,
                'errors' => $errors,
            ];
        }

        $line_number = 1;
        foreach ($this->serviceInvoiceItems as $invoice_item) {
            $params = [
                'Voucher' => 'D',
                'AccountType' => 'Ledger',
                'LedgerDimension' => $invoice_item->serviceItem->coaCode->code . '-' . $this->branch->code . '-' . $this->sbu->name,
                'Txt' => $invoice_item->serviceItem->code . ' ' . $invoice_item->serviceItem->description . ' ' . $invoice_item->description . '-' . $this->number . '-' . $this->customer->code,
                'AmountCurCredit' => $this->type_id == 1060 ? $invoice_item->sub_total : 0,
                // 'AmountCurCredit' => $this->type_id == 1061 ? $invoice_item->sub_total : 0,
                'TaxGroup' => '',
                'LineNum' => ++$line_number,
                // 'TVSSACCode' => ($invoice_item->serviceItem->taxCode != null) ? $invoice_item->serviceItem->taxCode->code : NULL,
            ];
            if ($this->type_id == 1061) {
                $params['AmountCurDebit'] = $this->type_id == 1061 ? $invoice_item->sub_total : 0;
            } elseif ($this->type_id == 1062) {
                $params['AmountCurDebit'] = $this->type_id == 1062 ? $invoice_item->sub_total : 0;
            } else {
                $params['AmountCurDebit'] = 0;
            }

            if ($invoice_item->serviceItem->taxCode && $KFC_IN == 0) {
                if ($invoice_item->serviceItem->taxCode->type_id == 1020) {
                    //HSN Code
                    $params['TVSHSNCode'] = $invoice_item->serviceItem->taxCode->code;
                    $params['TVSSACCode'] = '';
                } else {
                    $params['TVSHSNCode'] = '';
                    $params['TVSSACCode'] = $invoice_item->serviceItem->taxCode->code;
                }
            } else {
                $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
            }
            // dump($params);
            $this->exportRowToAxapta($params);

            $service_invoice = $invoice_item->serviceInvoice()->with([
                'toAccountType',
                // 'customer',
                // 'customer.primaryAddress',
                'branch',
                'branch.primaryAddress',
            ])
                ->first();

            $date1 = Carbon::createFromFormat('d-m-Y', '31-07-2021');
            $date2 = Carbon::createFromFormat('d-m-Y', date('d-m-Y', strtotime($service_invoice->document_date)));
            $kfc_result = $date1->gte($date2);

            $service_invoice->address;
            foreach ($invoice_item->taxes as $invoice_tax) {
                // dump($invoice_tax);
                if ($invoice_tax->name == 'CGST') {
                    $invoice_cgst_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'SGST') {
                    $invoice_sgst_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'IGST') {
                    $invoice_igst_percentage = $invoice_tax->pivot->percentage;
                }
                if ($invoice_tax->name == 'KFC') {
                    $invoice_kfc_percentage = $invoice_tax->pivot->percentage;
                }
            }
            // $service_invoice->customer->primaryAddress;
            // dump($service_invoice);
            // dd(1);
            if (!empty($service_invoice)) {

                if ($service_invoice->address->state_id) {
                    if ($service_invoice->address->state_id == 3 && $service_invoice->branch->primaryAddress->state_id == 3 && empty($service_invoice->address->gst_number) && $service_invoice->type_id != 1060 && $kfc_result) {
                        // if ($service_invoice->type_id != 1060) {
                        // if (empty($service_invoice->address->gst_number)) {
                        //FOR AXAPTA EXPORT WHILE GETING KFC ADD SEPERATE TAX LIKE CGST,SGST
                        if (!empty($invoice_item->serviceItem->taxCode)) {
                            foreach ($invoice_item->serviceItem->taxCode->taxes as $tax) {
                                //FOR CGST
                                if ($tax->name == 'CGST') {
                                    if ($this->type_id == 1061) {
                                        $params['AmountCurDebit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } elseif ($this->type_id == 1062) {
                                        $params['AmountCurDebit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } else {
                                        $params['AmountCurDebit'] = 0;
                                    }

                                    $params['AmountCurCredit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    $params['LedgerDimension'] = '7132' . '-' . $this->branch->code . '-' . $this->sbu->name;

                                    $params['LineNum'] = ++$line_number;
                                    // dump($params['LineNum']);
                                    $line_number = $params['LineNum'];

                                    //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
                                    $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
                                    // dump($params);
                                    $this->exportRowToAxapta($params);
                                }
                                //FOR CGST
                                if ($tax->name == 'SGST') {
                                    if ($this->type_id == 1061) {
                                        $params['AmountCurDebit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } elseif ($this->type_id == 1062) {
                                        $params['AmountCurDebit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } else {
                                        $params['AmountCurDebit'] = 0;
                                    }

                                    $params['AmountCurCredit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    $params['LedgerDimension'] = '7432' . '-' . $this->branch->code . '-' . $this->sbu->name;

                                    $params['LineNum'] = ++$line_number;
                                    // dump($params['LineNum']);
                                    $line_number = $params['LineNum'];

                                    //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
                                    $params['TVSHSNCode'] = $params['TVSSACCode'] = null;

                                    // dump($params);
                                    $this->exportRowToAxapta($params);
                                }
                            }
                            //FOR KFC
                            if ($invoice_item->serviceItem->taxCode) {
                                if ($this->type_id == 1061) {
                                    $params['AmountCurDebit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                } elseif ($this->type_id == 1062) {
                                    $params['AmountCurDebit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                } else {
                                    $params['AmountCurDebit'] = 0;
                                }
                                $params['AmountCurCredit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * 1 / 100, 2) : 0;
                                $params['LedgerDimension'] = '2230' . '-' . $this->branch->code . '-' . $this->sbu->name;

                                $params['LineNum'] = ++$line_number;
                                // dump($params['LineNum']);
                                $line_number = $params['LineNum'];

                                //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
                                $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
                                // dump($params);
                                $this->exportRowToAxapta($params);
                            }

                        }
                        // }
                        // }
                    } else {
                        if (!empty($invoice_item->serviceItem->taxCode)) {
                            foreach ($invoice_item->serviceItem->taxCode->taxes as $tax) {
                                //FOR CGST
                                if ($tax->name == 'CGST' && $invoice_cgst_percentage != 0.00) {
                                    if ($this->type_id == 1061) {
                                        $params['AmountCurDebit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } elseif ($this->type_id == 1062) {
                                        $params['AmountCurDebit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } else {
                                        $params['AmountCurDebit'] = 0;
                                    }

                                    $params['AmountCurCredit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    $params['LedgerDimension'] = $this->branch->primaryAddress->state->cgst_coa_code . '-' . $this->branch->code . '-' . $this->sbu->name;

                                    $params['LineNum'] = ++$line_number;
                                    // dump($params['LineNum']);
                                    $line_number = $params['LineNum'];

                                    //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
                                    $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
                                    // dump($params);
                                    $this->exportRowToAxapta($params);
                                }
                                //FOR CGST
                                if ($tax->name == 'SGST' && $invoice_sgst_percentage != 0.00) {
                                    if ($this->type_id == 1061) {
                                        $params['AmountCurDebit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } elseif ($this->type_id == 1062) {
                                        $params['AmountCurDebit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } else {
                                        $params['AmountCurDebit'] = 0;
                                    }

                                    $params['AmountCurCredit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    $params['LedgerDimension'] = $this->branch->primaryAddress->state->sgst_coa_code . '-' . $this->branch->code . '-' . $this->sbu->name;

                                    $params['LineNum'] = ++$line_number;
                                    // dump($params['LineNum']);
                                    $line_number = $params['LineNum'];

                                    //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
                                    $params['TVSHSNCode'] = $params['TVSSACCode'] = null;

                                    // dump($params);
                                    $this->exportRowToAxapta($params);
                                }
                                //FOR IGST
                                if ($tax->name == 'IGST' && $invoice_igst_percentage != 0.00) {
                                    if ($this->type_id == 1061) {
                                        $params['AmountCurDebit'] = $this->type_id == 1061 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } elseif ($this->type_id == 1062) {
                                        $params['AmountCurDebit'] = $this->type_id == 1062 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    } else {
                                        $params['AmountCurDebit'] = 0;
                                    }

                                    $params['AmountCurCredit'] = $this->type_id == 1060 ? round($invoice_item->sub_total * $tax->pivot->percentage / 100, 2) : 0;
                                    $params['LedgerDimension'] = $this->branch->primaryAddress->state->igst_coa_code . '-' . $this->branch->code . '-' . $this->sbu->name;

                                    $params['LineNum'] = ++$line_number;
                                    // dump($params['LineNum']);
                                    $line_number = $params['LineNum'];

                                    //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
                                    $params['TVSHSNCode'] = $params['TVSSACCode'] = null;

                                    // dump($params);
                                    $this->exportRowToAxapta($params);
                                }
                            }
                        }
                    }
                }
            }

        }
        // dd($tcs_total['invoice']);
        //FOR TCS TAX
        // dump($tcs_total['invoice'], $tcs_total['credit'], $tcs_total['debit']);
        if ($tcs_total['invoice'] != 0 || $tcs_total['credit'] != 0 || $tcs_total['debit'] != 0) {
            // dd('in');
            if ($this->type_id == 1061) {
                $params['AmountCurDebit'] = $this->type_id == 1061 ? round($tcs_total['debit'], 2) : 0;
            } elseif ($this->type_id == 1062) {
                $params['AmountCurDebit'] = $this->type_id == 1062 ? round($tcs_total['invoice'], 2) : 0;
            } else {
                $params['AmountCurDebit'] = 0;
            }
            $params['AmountCurCredit'] = $this->type_id == 1060 ? round($tcs_total['credit'], 2) : 0;
            $params['LedgerDimension'] = '2269' . '-' . $this->branch->code . '-' . $this->sbu->name;

            $params['LineNum'] = ++$line_number;
            // dump($params['LineNum']);
            $line_number = $params['LineNum'];

            //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
            $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
            // dump($params);
            $this->exportRowToAxapta($params);
        }

        //FOR CESS on GST TAX
        // dump($cess_on_gst_total['invoice'], $cess_on_gst_total['credit'], $cess_on_gst_total['debit']);
        if ($cess_on_gst_total['invoice'] != 0 || $cess_on_gst_total['credit'] != 0 || $cess_on_gst_total['debit'] != 0) {
            // dd('in');
            if ($this->type_id == 1061) {
                $params['AmountCurDebit'] = $this->type_id == 1061 ? round($cess_on_gst_total['debit'], 2) : 0;
            } elseif ($this->type_id == 1062) {
                $params['AmountCurDebit'] = $this->type_id == 1062 ? round($cess_on_gst_total['invoice'], 2) : 0;
            } else {
                $params['AmountCurDebit'] = 0;
            }
            $params['AmountCurCredit'] = $this->type_id == 1060 ? round($cess_on_gst_total['credit'], 2) : 0;
            $params['LedgerDimension'] = $this->branch->primaryAddress->state->cess_on_gst_coa_code . '-' . $this->branch->code . '-' . $this->sbu->name;

            $params['LineNum'] = ++$line_number;
            // dump($params['LineNum']);
            $line_number = $params['LineNum'];

            //REMOVE or PUT EMPTY THIS COLUMN WHILE KFC COMMING
            $params['TVSHSNCode'] = $params['TVSSACCode'] = null;
            // dump($params);
            $this->exportRowToAxapta($params);
        }

        if (!empty($service_invoice->round_off_amount) && $service_invoice->round_off_amount != '0.00') {
            if ($amount_diff > 0) {
                if ($this->type_id == 1061) {
                    $params['AmountCurDebit'] = $this->type_id == 1061 ? $amount_diff : 0;
                } elseif ($this->type_id == 1062) {
                    $params['AmountCurDebit'] = $this->type_id == 1062 ? $amount_diff : 0;
                } else {
                    $params['AmountCurDebit'] = 0;
                }
                $params['AmountCurCredit'] = $this->type_id == 1060 ? $amount_diff : "";
                $params['LedgerDimension'] = '3198' . '-' . $this->branch->code . '-' . $this->sbu->name;

                $params['LineNum'] = ++$line_number;
                // dump($params['LineNum']);
                $line_number = $params['LineNum'];
                // dump('if');
                // dd($params);
                $this->exportRowToAxapta($params);
            } else {
                if ($this->type_id == 1061) {
                    $params['AmountCurCredit'] = $this->type_id == 1061 ? ($amount_diff > 0 ? $amount_diff : $amount_diff * -1) : 0;
                } elseif ($this->type_id == 1062) {
                    $params['AmountCurCredit'] = $this->type_id == 1062 ? ($amount_diff > 0 ? $amount_diff : $amount_diff * -1) : 0;
                } else {
                    $params['AmountCurCredit'] = 0;
                }
                $params['AmountCurDebit'] = $this->type_id == 1060 ? ($amount_diff > 0 ? $amount_diff : $amount_diff * -1) : 0;
                $params['LedgerDimension'] = '3198' . '-' . $this->branch->code . '-' . $this->sbu->name;

                $params['LineNum'] = ++$line_number;
                // dump($params['LineNum']);
                $line_number = $params['LineNum'];
                // dump('else');
                // dd($params);
                $this->exportRowToAxapta($params);
            }
        }
        return [
            'success' => true,
        ];
    }

    protected function exportRowToAxapta($params)
    {
        // dd($params);
        // $invoice, $sno, $TransDate, $owner, $outlet, $coa_code, $ratio, $bank_detail, $rent_details, $debit, $credit, $voucher, $txt, $payment_modes, $flip, $account_type, $ledger_dimention, $sac_code, $sharing_type_id, $hsn_code = '', $tds_group_in = ''

        $export = new AxaptaExport([
            'company_id' => $this->company_id,
            'entity_type_id' => 1400,
            'entity_id' => $this->id,
            'LedgerDimension' => $params['LedgerDimension'],
        ]);

        $params['TVSHSNCode'] = isset($params['TVSHSNCode']) ? $params['TVSHSNCode'] : '';
        $export->Application = 'VIMS';
        $export->ApplicationType = 'CNDN';
        $export->CurrencyCode = 'INR';
        $export->JournalName = 'BPAS_NJV';
        $export->JournalNum = "";
        $export->Voucher = $params['Voucher'];
        //$export->LineNum = $params['LineNum'];
        $export->LineNum = $this->lineNumber++;
        $export->ApproverPersonnelNumber = $this->createdBy->employee->code;
        $export->Approved = 1;
        $export->TransDate = date("Y-m-d", strtotime($this->document_date));
        //dd($ledger_dimention);
        $export->AccountType = $params['AccountType'];

        $export->DefaultDimension = $this->sbu->name . '-' . $this->outlet->code;
        $export->Txt = $params['Txt'];
        $export->AmountCurDebit = $params['AmountCurDebit'] > 0 ? $params['AmountCurDebit'] : 0;
        $export->AmountCurCredit = $params['AmountCurCredit'] > 0 ? $params['AmountCurCredit'] : 0;
        $export->OffsetAccountType = '';
        $export->OffsetLedgerDimension = '';
        $export->OffsetDefaultDimension = '';
        $export->PaymMode = '';
        $export->TaxGroup = $params['TaxGroup'];
        $export->TaxItemGroup = '';
        $export->Invoice = $this->number;
        $export->SalesTaxFormTypes_IN_FormType = '';
        $export->TDSGroup_IN = $params['TaxGroup'];
        $export->DocumentNum = $this->number;
        $export->DocumentDate = date("Y-m-d", strtotime($this->document_date));
        $export->LogisticsLocation_LocationId = ($this->company_id == 1)?'000127079':'001342712';
        $export->Due = '';
        $export->PaymReference = '';
        $export->TVSHSNCode = $params['TVSHSNCode'];
        $export->TVSSACCode = $params['TVSSACCode'];
        $export->TVSVendorLocationID = '';
        // $export->TVSCustomerLocationID = $params['TVSHSNCode'] || $params['TVSSACCode'] ? $this->customer->axapta_location_id : ''; //SINGLE ADDRESS
        $export->TVSCustomerLocationID = $params['TVSHSNCode'] || $params['TVSSACCode'] ? $this->address->ax_customer_location_id : ''; //AFTER CHANGE MULTIPLE ADDRESS
        $export->TVSCompanyLocationId = ($params['TVSHSNCode'] || $params['TVSSACCode']) && $this->outlet->axapta_location_id ? $this->outlet->axapta_location_id : '';
        $ax_export_status = AxExportStatus::where('code', 'pending')->first();
        $export->sync_status_id = $ax_export_status->id;
        $export->save();

    }

    public static function importFromExcel($job)
    {
        try {
            $response = ImportCronJob::getRecordsFromExcel($job, 'N');
            $rows = $response['rows'];
            $header = $response['header'];

            $all_error_records = [];
            $i = 0;
            foreach ($rows as $k => $row) {
                $record = [];
                foreach ($header as $key => $column) {
                    if (!$column) {
                        continue;
                    } else {
                        $record[$column] = trim($row[$key]);
                    }
                }
                if (empty($record['SNO'])) {
                    // exit;
                } else {
                    // dump('first Sheet');
                    // dump($record['SNO']);

                    $original_record = $record;
                    $status = [];
                    $status['errors'] = [];

                    $user = User::find($job->created_by_id);
                    if (!$user) {
                        $status['errors'][] = 'User Not Found!';
                    } else {
                        $employee = Employee::find($user->entity_id);
                        // $employee_outlets = $employee->employee_outlets()->pluck('outlet_id')->toArray();
                        // $employee_sbus = $employee->employee_sbus()->pluck('sbu_id')->toArray();
                        // dump($employee_outlets);
                        // dd($employee_sbus);
                    }

                    if (empty($record['SNO'])) {
                        $status['errors'][] = 'SNO is empty';
                    } else {
                        $sno = intval($record['SNO']);
                        if (!$sno) {
                            $status['errors'][] = 'Invalid SNO';
                        }
                    }

                    if (empty($record['Type'])) {
                        $status['errors'][] = 'Type is empty';
                    } else {
                        $type = Config::where([
                            'config_type_id' => 84,
                            'name' => $record['Type'],
                        ])->first();
                        if (!$type) {
                            $status['errors'][] = 'Invalid Type';
                        }
                    }

                    if (empty($record['Doc Date'])) {
                        $status['errors'][] = 'Doc Date is empty';
                    } else {
                        if (!is_numeric($record['Doc Date'])) {
                            $status['errors'][] = 'Invalid Date Format';
                        } else {
                            $doc_date = $record['Doc Date'];
                        }
                    }

                    if (empty($record['Branch'])) {
                        $status['errors'][] = 'Branch is empty';
                    } else {
                        // $branches = Employee::where()
                        $branch = Outlet::where([
                            'company_id' => $job->company_id,
                            'code' => $record['Branch'],
                        ])->first();
                        if (!$branch) {
                            $status['errors'][] = 'Invalid Branch';
                        }
                        $employee_outlte = $employee->employee_outlets;
                        if (!$employee_outlte) {
                            $status['errors'][] = 'Outlet is not mapped for your employee code';
                        }
                    }

                    if (empty($record['SBU'])) {
                        $status['errors'][] = 'SBU is empty';
                    } else {
                        $sbu = Sbu::where([
                            'company_id' => $job->company_id,
                            'name' => $record['SBU'],
                        ])->first();
                        if (!$sbu) {
                            $status['errors'][] = 'Invalid SBU';
                        } else {
                            if (empty($sbu->business_id)) {
                                $status['errors'][] = 'Business Not Mapped with this SBU';
                            }
                            if (!empty($branch)) {
                                $outlet_sbu = $branch->outlet_sbu;
                                if (!$outlet_sbu) {
                                    $status['errors'][] = 'SBU is not mapped for this branch';
                                }
                            }
                        }
                    }

                    if (empty($record['Category'])) {
                        $status['errors'][] = 'Category is empty';
                    } else {
                        $category = ServiceItemCategory::where([
                            'company_id' => $job->company_id,
                            'name' => $record['Category'],
                        ])->first();
                        if (!$category) {
                            $status['errors'][] = 'Invalid Category';
                        }
                        // else {
                        //     if (empty($record['Sub Category'])) {
                        //         $status['errors'][] = 'Sub Category is empty';
                        //     } else {
                        //         $sub_category = ServiceItemSubCategory::where([
                        //             'company_id' => $job->company_id,
                        //             'category_id' => $category->id,
                        //             'name' => $record['Sub Category'],
                        //         ])->first();
                        //         if (!$sub_category) {
                        //             $status['errors'][] = 'Invalid Sub Category Or Sub Category is not mapped for this Category';
                        //         }
                        //     }
                        // }
                    }

                    if (empty($record['Is Service'])) {
                        $status['errors'][] = 'Is Service is empty';
                    } else {
                        if ($record['Is Service'] == 'Yes') {
                            $is_service = 1;
                        } elseif ($record['Is Service'] == 'No') {
                            $is_service = 0;
                        } elseif ($record['Is Service'] == 'Non-Taxable') {
                            $is_service = 2;
                        }
                        if (!$is_service && $is_service != 0) {
                            $status['errors'][] = 'Invalid Service';
                        }
                    }

                    if (empty($record['Reverse Charge Applicable'])) {
                        $status['errors'][] = 'Reverse Charge Applicable is empty';
                    } else {
                        if ($record['Reverse Charge Applicable'] == 'No') {
                            $is_reverse_charge_applicable = 0;
                        } else {
                            $is_reverse_charge_applicable = 1;
                        }
                        // dump($is_reverse_charge_applicable);
                        if (empty($is_reverse_charge_applicable) && $is_reverse_charge_applicable != 0) {
                            $status['errors'][] = 'Invalid Reverse Charge Applicable';
                        }
                    }
                    // dd($is_reverse_charge_applicable);

                    $po_reference_number = !empty($record['PO Reference Number']) ? $record['PO Reference Number'] : null;
                    // dd($record);
                    $reference_invoice_number = !empty($record['Reference Invoice Number']) ? $record['Reference Invoice Number'] : null;
                    $reference_invoice_date = !empty($record['Reference Invoice Date']) ? date('Y-m-d', PHPExcel_Shared_Date::ExcelToPHP($reference_invoice_date)) : null;
                    // dump($po_reference_number, $reference_invoice_date, $reference_invoice_number);
                    // dd();

                    if (empty($record['To Account Type'])) {
                        $status['errors'][] = 'To Account Type is empty';
                    } else {
                        if ($record['To Account Type'] == 'Customer' || $record['To Account Type'] == 'customer') {
                            $to_account_type_id = 1440;
                        } elseif ($record['To Account Type'] == 'Vendor' || $record['To Account Type'] == 'vendor') {
                            $to_account_type_id = 1441;
                        }
                        if (!$to_account_type_id) {
                            $status['errors'][] = 'Invalid To Account Type';
                        }
                    }
                    // dump($to_account_type_id . 'to_account_type_id');
                    // dump($record);
                    // dump($record['Customer/Vendor Code']);
                    if (empty($record['Customer/Vendor Code'])) {
                        $status['errors'][] = 'Customer Code is empty';
                    } else {
                        $customer = '';
                        if ($to_account_type_id == 1440) {
                            //UPDATE CUSTOMER AND ADDRESS
                            try {
                                // $customer = ServiceInvoiceController::searchCustomerImport(trim($record['Customer/Vendor Code']), $job);
                                // $obj = new ServiceInvoiceApprovalController;
                                $obj = new ServiceInvoiceController;
                                // $customer = $obj->customerImportNew(trim($record['Customer/Vendor Code']), $job);
                                $customer = $obj->customerUniqueDetailsSearch(trim($record['Customer/Vendor Code']), $job);
                                // dd(123);
                            } catch (\SoapFault $e) {
                                $status['errors'][] = 'Somthing went worng in SOAP Service Call!';
                            } catch (\Exception $e) {
                                $status['errors'][] = 'Somthing went worng. Try again later!';
                            }
                            // dd($customer);
                            //CUSTOMER
                            $customer = Customer::where([
                                'company_id' => $job->company_id,
                                'code' => trim($record['Customer/Vendor Code']),
                            ])->first();
                            if (!$customer) {
                                $status['errors'][] = 'Invalid Customer: ' . $record['Customer/Vendor Code'];
                            }
                            if ($customer->id) {
                                $customer_address = Address::where([
                                    'company_id' => $job->company_id,
                                    'entity_id' => $customer->id,
                                    'address_of_id' => 24, //CUSTOMER
                                    'is_primary' => 1, //PRIMARY
                                ])
                                // ->orderBy('id', 'desc')
                                    ->first();
                            }
                            if (!$customer_address) {
                                $status['errors'][] = 'Address Not Mapped with Customer: ' . $record['Customer/Vendor Code'];
                            } else {
                                if (!$customer_address->state_id) {
                                    $status['errors'][] = 'State Not Mapped with this Customer: ' . $record['Customer/Vendor Code'];
                                }
                            }
                        } elseif ($to_account_type_id == 1441) {
                            //VENDOR
                            $customer = Vendor::where([
                                'company_id' => $job->company_id,
                                'code' => trim($record['Customer/Vendor Code']),
                            ])->first();
                            if (!$customer) {
                                $status['errors'][] = 'Invalid Vendor';
                            }
                            if ($customer->id) {
                                $vendor_address = Address::where([
                                    'company_id' => $job->company_id,
                                    'entity_id' => $customer->id,
                                    'address_of_id' => 21, //VENDOR
                                ])
                                    ->orderBy('id', 'desc')
                                    ->first();
                            }
                            if (!$vendor_address) {
                                $status['errors'][] = 'Address Not Mapped with Vendor';
                            }
                        }
                    }
                    // dump($customer->id . 'customer_id');

                    //GET FINANCIAL YEAR ID BY DOCUMENT DATE
                    try {
                        $date = PHPExcel_Shared_Date::ExcelToPHP($record['Doc Date']);
                        if (date('m', $date) > 3) {
                            $document_date_year = date('Y', $date) + 1;
                        } else {
                            $document_date_year = date('Y', $date);
                        }

                        $financial_year = FinancialYear::where('from', $document_date_year)
                            // ->where('company_id', $job->company_id)
                            ->first();
                        if (!$financial_year) {
                            $status['errors'][] = 'Fiancial Year Not Found';
                        }
                    } catch (\Exception $e) {
                        $status['errors'][] = 'Invalid Date Format';

                    }

                    if ($type) {
                        if ($type->id == 1061) {
                            //DN
                            $serial_number_category = 5;
                        } elseif ($type->id == 1060) {
                            //CN
                            $serial_number_category = 4;
                        } elseif ($type->id == 1062) {
                            //INV
                            $serial_number_category = 125;
                        }
                        // dd($branch, $sbu, $financial_year);
                        if ($branch && $sbu && $financial_year) {
                            //GENERATE SERVICE INVOICE NUMBER
                            if ($category->id == 4) {
                                if ($type->id == 1061) {
                                    //DN
                                    $serial_number_category = 128;
                                } elseif ($type->id == 1060) {
                                    //CN
                                    $serial_number_category = 127;
                                } elseif ($type->id == 1062) {
                                    //INV
                                    $serial_number_category = 126;
                                }
                                $generateNumber = SerialNumberGroup::generateNumber($serial_number_category, $financial_year->id, $branch->state_id, null, null, null);
                                if (!$generateNumber['success']) {
                                    $status['errors'][] = 'No Serial number found';
                                    // dd($status['errors']);
                                }
                            } else {
                                //STATE BUSINESS BASED CODE
                                $generateNumber = SerialNumberGroup::generateNumber($serial_number_category, $financial_year->id, $branch->state_id, null, null, $sbu->business_id);
                                if (!$generateNumber['success']) {
                                    $status['errors'][] = 'No Serial number found';
                                }
                            }
                            // $generateNumber = SerialNumberGroup::generateNumber($serial_number_category, $financial_year->id, $branch->state_id, $branch->id, $sbu);
                            // if (!$generateNumber['success']) {
                            // $status['errors'][] = 'No Serial number found';
                            // }
                        }

                    }
                    dump($generateNumber);
                    // dd($status);

                    $approval_status = Entity::select('entities.name')->where('company_id', $job->company_id)->where('entity_type_id', 18)->first();
                    if ($approval_status) {
                        $status_id = $approval_status->name;
                    } else {
                        $status['errors'][] = 'Initial CN/DN Status has not mapped.!';
                    }
                    // dd($customer->id);
                    if (count($status['errors']) > 0) {
                        dump($status['errors']);
                        $original_record['Record No'] = $k + 1;
                        $original_record['Error Details'] = implode(',', $status['errors']);
                        $all_error_records[] = $original_record;
                        $job->incrementError();
                        continue;
                    }
                    // dd('done');
                    //STATICALLY GET SECOND SHEET FROM EXCEL
                    $objPHPExcel = PHPExcel_IOFactory::load(storage_path('app/' . $job->src_file));
                    $sheet = $objPHPExcel->getSheet(1);
                    $highestRow = $sheet->getHighestDataRow();

                    $header = $sheet->rangeToArray('A1:F1', null, true, false);
                    $header = $header[0];
                    $rows = $sheet->rangeToArray('A2:F2' . $highestRow, null, true, false);

                    $amount_total = 0;
                    $sub_total = 0;
                    $total = 0;
                    $invoice_amount = 0;
                    foreach ($rows as $k => $row) {
                        $item_record = [];
                        foreach ($header as $key => $column) {
                            if (!$column) {
                                continue;
                            } else {
                                $item_record[$column] = trim($row[$key]);
                            }
                        }
                        //Check Row Empty or not
                        if (count(array_filter($row)) == 0) {
                            // $status['errors'][] = 'Row is empty';
                            continue;
                        } else {
                            // dump($customer->id);
                            // dump('2 Sheet');

                            if ($item_record['SNO'] == $sno) {
                                // dump($item_record['SNO']);
                                // dump($sno);

                                $original_record = $item_record;
                                // $status = [];
                                // $status['errors'] = [];
                                // dd($item_record);
                                if (empty($item_record['SNO'])) {
                                    $status['errors'][] = 'SNO is empty';
                                } else {
                                    $item_sno = intval($item_record['SNO']);
                                    if (!$item_sno) {
                                        $status['errors'][] = 'Invalid SNO';
                                    }
                                }
                                // $request = request();
                                if (empty($item_record['Item Code'])) {
                                    $status['errors'][] = 'Item Code is empty';
                                } else {
                                    $list = ServiceItem::
                                        leftJoin('service_item_sub_categories', 'service_item_sub_categories.id', 'service_items.sub_category_id')->leftJoin('tax_codes', 'tax_codes.id', 'service_items.sac_code_id')
                                        ->where(['service_items.company_id' => $job->company_id, 'service_item_sub_categories.category_id' => $category->id])
                                        ->select(
                                            'service_items.id',
                                            'service_items.name',
                                            'service_items.code',
                                            'tax_codes.type_id'
                                        )
                                    ;
                                    if ($is_service == 1) {
                                        $list = $list->where('tax_codes.type_id', 1021); //SAC CODE
                                    } elseif ($is_service == 0) {
                                        $list = $list->where('tax_codes.type_id', 1020); //HSN CODE
                                    } else {
                                        $list = $list->whereNull('sac_code_id'); //No Tax Code
                                    }

                                    $input_item_code = $item_record['Item Code'];
                                    // dump($input_item_code);

                                    $list = $list->where(function ($q) use ($input_item_code) {
                                        $q->where('service_items.code', $input_item_code);
                                    })
                                    // // ->where([
                                    // //     'company_id' => $job->company_id,
                                    // //     'code' => trim($item_record['Item Code']),
                                    // // ])
                                    // ->get()
                                        ->first();

                                    $item_code = $list;
                                    // dump($item_code);
                                    if (!$item_code) {
                                        $status['errors'][] = 'Invalid Item Code. Not Mapped with Tax code or Category!';
                                    }
                                }
                                // dd($status['errors']);

                                if (empty($item_record['UOM'])) {
                                    $status['errors'][] = 'UOM is empty';
                                } else {
                                    $uom = EInvoiceUom::where([
                                        'company_id' => $job->company_id,
                                        'code' => trim($item_record['UOM']),
                                    ])->first();
                                    if (!$uom) {
                                        $status['errors'][] = 'Invalid UOM';
                                    }
                                }

                                if (empty($item_record['Reference'])) {
                                    $status['errors'][] = 'Reference is empty';
                                }

                                if (empty($item_record['Quantity'])) {
                                    $status['errors'][] = 'Quantity is empty';
                                }

                                if (empty($item_record['Amount'])) {
                                    $status['errors'][] = 'Amount is empty';
                                } elseif (!is_numeric($item_record['Amount'])) {
                                    $status['errors'][] = 'Invalid Amount';
                                }

                                $taxes = [];
                                if ($item_code && $branch && $customer) {
                                    $taxes = Tax::getTaxes($item_code->id, $branch->id, $customer->id, $to_account_type_id, $customer_address->state_id);
                                    // $taxes = Tax::getTaxes($request->service_item_id, $request->branch_id, $request->customer_id, $request->to_account_type_id, $request->state_id);
                                    if (!$taxes['success']) {
                                        $status['errors'][] = $taxes['error'];
                                    }
                                }
                                // dump($taxes);
                                // dd($item_code);
                                if (!empty($item_code) && !empty($taxes)) {
                                    // dd('in');
                                    $service_item = ServiceItem::with([
                                        'coaCode',
                                        'taxCode',
                                        'taxCode.taxes' => function ($query) use ($taxes) {
                                            if (!empty($taxes)) {
                                                $query->whereIn('tax_id', $taxes['tax_ids']);
                                            }
                                        },
                                    ])
                                        ->find($item_code->id);
                                    // dd($service_item);
                                    if (!$service_item) {
                                        $status['errors'][] = 'Service Item not found';
                                    }
                                } else {
                                    // dd('else');
                                    $status['errors'][] = 'Item is not mapped and taxes are empty!';
                                }
                                if (!isset($generateNumber) || !$generateNumber['success'] || empty($generateNumber['success'])) {
                                    $status['errors'][] = 'No Serial number found';
                                }

                                //dump($status['errors']);
                                if (count($status['errors']) > 0) {
                                    dump($status['errors']);
                                    $original_record['Record No'] = $k + 1;
                                    $original_record['Error Details'] = implode(',', $status['errors']);
                                    $all_error_records[] = $original_record;
                                    $job->incrementError();
                                    continue;
                                }
                                // dd(date('Y-m-d', PHPExcel_Shared_Date::ExcelToPHP($doc_date)));
                                DB::beginTransaction();

                                // dd(Auth::user()->company_id);

                                $service_invoice = ServiceInvoice::where([
                                    'company_id' => $job->company_id,
                                    'number' => $generateNumber['number'],
                                ])->first();
                                if (!$service_invoice) {
                                    $service_invoice = new ServiceInvoice();
                                }

                                $service_invoice->company_id = $job->company_id;
                                $service_invoice->number = $generateNumber['number'];
                                dump($generateNumber);
                                // dump($service_invoice);
                                if ($type->id == 1061) {
                                    //DN
                                    $service_invoice->is_cn_created = 0;
                                } elseif ($type->id == 1060) {
                                    //CN
                                    $service_invoice->is_cn_created = 1;
                                } elseif ($type->id == 1062) {
                                    //INV
                                    $service_invoice->is_cn_created = 0;
                                }

                                $service_invoice->company_id = $job->company_id;
                                $service_invoice->type_id = $type->id;
                                $service_invoice->branch_id = $branch->id;
                                $service_invoice->sbu_id = $sbu->id;
                                $service_invoice->category_id = $category->id;
                                // $service_invoice->sub_category_id = $sub_category->id;
                                $service_invoice->document_date = date('Y-m-d', PHPExcel_Shared_Date::ExcelToPHP($doc_date));
                                $service_invoice->is_service = $is_service;
                                $service_invoice->is_reverse_charge_applicable = $is_reverse_charge_applicable;
                                $service_invoice->po_reference_number = $po_reference_number;
                                $service_invoice->invoice_number = $reference_invoice_number;
                                $service_invoice->invoice_date = $reference_invoice_date;
                                $service_invoice->to_account_type_id = $to_account_type_id;
                                $service_invoice->customer_id = $customer->id;
                                $service_invoice->address_id = $to_account_type_id == 1440 ? ($customer_address ? $customer_address->id : null) : ($vendor_address ? $vendor_address->id : null);
                                $message = 'Service invoice added successfully';
                                $service_invoice->items_count = 1;
                                $service_invoice->e_invoice_registration = 0; //FOR ONLY B2C CUSTOMERS
                                $service_invoice->status_id = $status_id;
                                $service_invoice->created_by_id = $job->created_by_id;
                                $service_invoice->updated_at = null;
                                // dump($service_invoice);
                                // dd(1);
                                $service_invoice->save();

                                // $service_invoice_item = ServiceInvoiceItem::firstOrNew([
                                // 'service_invoice_id' => $service_invoice->id,
                                // 'service_item_id' => $item_code->id,
                                // ]);
                                $service_invoice_item = new ServiceInvoiceItem;
                                $service_invoice_item->service_invoice_id = $service_invoice->id;
                                $service_invoice_item->service_item_id = $item_code->id;
                                $service_invoice_item->e_invoice_uom_id = $uom->id;
                                $service_invoice_item->description = $item_record['Reference'];
                                // $service_invoice_item->qty = 1;
                                // $service_invoice_item->sub_total = 1 * $record['Amount'];
                                $service_invoice_item->qty = $item_record['Quantity'];
                                $service_invoice_item->rate = $item_record['Amount'];
                                $service_invoice_item->sub_total = $item_record['Quantity'] * $item_record['Amount'];
                                $service_invoice_item->save();
                                // dump($service_invoice_item);
                                // dd(1);

                                //SAVE SERVICE INVOICE ITEM TAX
                                $gst_total = 0;
                                $KFC_tax_amount = 0;
                                $TCS_tax_amount = 0;
                                $tcs_total = 0;
                                $cess_gst_tax_amount = 0;
                                $cess_gst_total = 0;
                                if (!is_null($service_item->sac_code_id)) {
                                    if ($service_item) {
                                        //TAX CALCULATION
                                        if (count($service_item->taxCode->taxes) > 0) {
                                            foreach ($service_item->taxCode->taxes as $key => $value) {
                                                // dump($value->id);
                                                $gst_total += round(($value->pivot->percentage / 100) * ($item_record['Quantity'] * $item_record['Amount']), 2);
                                                if ($value->id == 1 || $value->id == 2) {
                                                    //CGST AND SGST
                                                    $item_taxes[$value->id] = [
                                                        'percentage' => round($value->pivot->percentage, 2),
                                                        'amount' => round(($value->pivot->percentage / 100) * ($item_record['Quantity'] * $item_record['Amount']), 2),
                                                    ];
                                                    //IGST
                                                    $item_taxes[3] = [
                                                        'percentage' => 0,
                                                        'amount' => 0,
                                                    ];
                                                } elseif ($value->id == 3) {
                                                    //IGST
                                                    $item_taxes[$value->id] = [
                                                        'percentage' => round($value->pivot->percentage, 2),
                                                        'amount' => round(($value->pivot->percentage / 100) * ($item_record['Quantity'] * $item_record['Amount']), 2),
                                                    ];
                                                    //CGST
                                                    $item_taxes[1] = [
                                                        'percentage' => 0,
                                                        'amount' => 0,
                                                    ];
                                                    //SGST
                                                    $item_taxes[2] = [
                                                        'percentage' => 0,
                                                        'amount' => 0,
                                                    ];
                                                }
                                            }
                                        }

                                        //CALCULATE KFC
                                        if (($customer_address->state_id == 3) && ($branch->state_id == 3) && (empty($customer_address->gst_number) && ($type->id != 1060))) {
                                            //3 FOR KERALA
                                            //check customer state and outlet states are equal KL.  //add KFC tax
                                            //customer dont't have GST
                                            //customer have HSN and SAC Code
                                            // $gst_total += round((1 / 100) * ($item_record['Quantity'] * $item_record['Amount']), 2);
                                            // $KFC_tax_amount = round($item_record['Quantity'] * $item_record['Amount'] * 1 / 100, 2); //ONE PERCENTAGE
                                            // $item_taxes[4] = [ //4 for KFC
                                            //     'percentage' => 1,
                                            //     'amount' => $KFC_tax_amount,
                                            // ];
                                            $item_taxes[4] = [ //4 for KFC
                                                'percentage' => 0,
                                                'amount' => 0,
                                            ];
                                        } else {
                                            $item_taxes[4] = [ //4 for KFC
                                                'percentage' => 0,
                                                'amount' => 0,
                                            ];
                                        }

                                        //TCS PERCANTAGE
                                        // if ($service_item->tcs_percentage && $service_item->is_tcs ==1) {
                                        if ($service_item->tcs_percentage) {

                                            $document_date = (string) $service_invoice->document_date;
                                            $date1 = Carbon::createFromFormat('d-m-Y', '31-03-2021');
                                            $date2 = Carbon::createFromFormat('d-m-Y', $document_date);
                                            $result = $date1->gte($date2);

                                            $tcs_limit = Entity::where('entity_type_id', 38)->where('company_id', Auth::user()->company_id)->pluck('name')->first();
                                            $tcs_percentage = 0;
                                            if (($item_record['Amount'] * $item_record['Quantity']) >= $tcs_limit) {
                                                $tcs_percentage = $service_item->tcs_percentage;
                                                if (!$result) {
                                                    $tcs_percentage = 1;
                                                }
                                            }

                                            // $gst_total += round(($service_item->tcs_percentage / 100) * ($request->qty * $request->amount), 2);
                                            // $tcs_total += round(($gst_total + $item_record['Quantity'] * $item_record['Amount']) * $service_item->tcs_percentage / 100, 2);
                                            $tcs_total += round(($gst_total + $item_record['Quantity'] * $item_record['Amount']) * $tcs_percentage / 100, 2);
                                            // dd($tcs_total);
                                            // $TCS_tax_amount = round(($gst_total + $item_record['Quantity'] * $item_record['Amount']) * $service_item->tcs_percentage / 100, 2); //ONE PERCENTAGE FOR TCS
                                            $TCS_tax_amount = round(($gst_total + $item_record['Quantity'] * $item_record['Amount']) * $tcs_percentage / 100, 2); //ONE PERCENTAGE FOR TCS
                                            $item_taxes[5] = [ // for TCS
                                                // 'percentage' => $service_item->tcs_percentage,
                                                'percentage' => $tcs_percentage,
                                                'amount' => $TCS_tax_amount,
                                            ];
                                        } else {
                                            $item_taxes[5] = [ // for TCS
                                                'percentage' => 0,
                                                'amount' => 0,
                                            ];
                                        }

                                        if ($service_item->cess_on_gst_percentage) {
                                            $cess_gst_total += round(($item_record['Quantity'] * $item_record['Amount']) * $service_item->cess_on_gst_percentage / 100, 2);
                                            $cess_gst_tax_amount = round(($item_record['Quantity'] * $item_record['Amount']) * $service_item->cess_on_gst_percentage / 100, 2); //PERCENTAGE FOR CESS on GST
                                            $item_taxes[6] = [ // for CESS on GST
                                                'percentage' => $service_item->cess_on_gst_percentage,
                                                'amount' => $cess_gst_tax_amount,
                                            ];
                                        } else {
                                            $item_taxes[6] = [ // for CESS on GST
                                                'percentage' => 0,
                                                'amount' => 0,
                                            ];
                                        }
                                    }
                                    // dump($item_taxes);
                                    $service_invoice_item->taxes()->sync($item_taxes);
                                }
                                // dump($gst_total);
                                // dump($tcs_total);
                                // dump($cess_gst_total);

                                $amount_total += $item_record['Amount'];
                                // dump($amount_total);

                                $sub_total += ($item_record['Quantity'] * $item_record['Amount']);
                                $total += ($item_record['Quantity'] * $item_record['Amount']) + $gst_total + $tcs_total + $cess_gst_total;

                                $invoice_amount += ($item_record['Quantity'] * $item_record['Amount']) + $gst_total + $tcs_total + $cess_gst_total;
                                $service_invoice->amount_total = $amount_total;
                                $service_invoice->tax_total = $gst_total + $tcs_total + $cess_gst_total;
                                $service_invoice->sub_total = $sub_total;
                                $service_invoice->total = $total;

                                $invoice_amount = number_format($invoice_amount, 2);
                                $invoice_amount = str_replace(',', '', $invoice_amount);
                                // dump($invoice_amount);

                                //FOR ROUND OFF
                                if ($invoice_amount > 1) {
                                    $round_off = 0;
                                    if ($invoice_amount < round($invoice_amount)) {
                                        $round_off = round($invoice_amount) - $invoice_amount;
                                    } else if ($invoice_amount > round($invoice_amount)) {
                                        $round_off = $invoice_amount - round($invoice_amount);
                                    } else {
                                        $round_off = 0;
                                    }
                                    $service_invoice->final_amount = round($invoice_amount);
                                } else {
                                    $round_off = 0;
                                    $service_invoice->final_amount = $invoice_amount;
                                }
                                // dump(number_format($round_off, 2));
                                // dd(round($invoice_amount));
                                $service_invoice->round_off_amount = number_format($round_off, 2);
                                // $service_invoice->final_amount = round($invoice_amount);
                                try {
                                    $service_invoice->save();
                                    DB::commit();
                                } catch (\Exception $e) {
                                    DB::rollback();
                                    $status['errors'][] = $e->getMessage();
                                    if (count($status['errors']) > 0) {
                                        dump($status['errors']);
                                        $original_record['Record No'] = $k + 1;
                                        $original_record['Error Details'] = implode(',', $status['errors']);
                                        $all_error_records[] = $original_record;
                                        $job->incrementError();
                                        continue;
                                    }

                                }

                                //UPDATING PROGRESS FOR EVERY FIVE RECORDS
                                if (($k + 1) % 5 == 0) {
                                    $job->save();
                                }
                            }
                        }

                    }
                    $job->incrementNew();
                    $i++;
                    $objPHPExcel = PHPExcel_IOFactory::load(storage_path('app/' . $job->src_file));
                    $sheet = $objPHPExcel->getSheet(0);
                    $highestRow = $sheet->getHighestDataRow();

                    $header = $sheet->rangeToArray('A1:N1', null, true, false);
                    $header = $header[0];
                    $rows = $sheet->rangeToArray('A' . $i . ':N' . $i . $highestRow, null, true, false);
                    // dump('-------------------------------------------------------');
                }
            }
            // dd(1);
            //COMPLETED or completed with errors
            $job->status_id = $job->error_count == 0 ? 7202 : 7205;
            $job->save();

            ImportCronJob::generateImportReport([
                'job' => $job,
                'all_error_records' => $all_error_records,
            ]);

        } catch (\Throwable $e) {
            $job->status_id = 7203; //Error
            $job->error_details = 'Error:' . $e->getMessage() . '. Line:' . $e->getLine() . '. File:' . $e->getFile(); //Error
            $job->save();
            dump($job->error_details);
        }

    }

    public function createPdf()
    {
        // dd('test');
        $r = $this->exportToAxapta();
        if (!$r['success']) {
            return $r;
        }

        $this->company->formatted_address = $this->company->primaryAddress ? $this->company->primaryAddress->getFormattedAddress() : 'NA';
        // $this->outlets->formatted_address = $this->outlets->primaryAddress ? $this->outlets->primaryAddress->getFormattedAddress() : 'NA';
        if ($this->number == 'F21MDSDN0001') {
            dump('static outlet');
            $this['branch_id'] = 134; //TRY - Trichy
            $this->outlets = $this->outlets ? $this->outlets : 'NA';
        } else {
            $this->outlets = $this->outlets ? $this->outlets : 'NA';
        }

        $this->customer->formatted_address = $this->customer->primaryAddress ? $this->customer->primaryAddress->address_line1 : 'NA';
        // $city = City::where('name', $this->customer->city)->first();
        // // dd($city);
        // $state = State::find($city->state_id);

        // dd($this->outlets->formatted_address);
        $fields = Field::withTrashed()->get()->keyBy('id');
        if (count($this->serviceInvoiceItems) > 0) {
            $array_key_replace = [];
            foreach ($this->serviceInvoiceItems as $key => $serviceInvoiceItem) {
                $taxes = $serviceInvoiceItem->taxes;
                $type = $serviceInvoiceItem->serviceItem;
                foreach ($taxes as $array_key_replace => $tax) {
                    $serviceInvoiceItem[$tax->name] = $tax;
                }
                //dd($type->sac_code_id);
            }
            //Field values
            $gst_total = 0;

            $additional_image_name = '';
            $additional_image_path = '';

            foreach ($this->serviceInvoiceItems as $key => $serviceInvoiceItem) {
                // dd($serviceInvoiceItem);
                $serviceInvoiceItem->eInvoiceUom;

                //FIELD GROUPS AND FIELDS INTEGRATION
                if (count($serviceInvoiceItem->eavVarchars) > 0) {
                    $eav_varchar_field_group_ids = $serviceInvoiceItem->eavVarchars()->pluck('field_group_id')->toArray();
                } else {
                    $eav_varchar_field_group_ids = [];
                }
                if (count($serviceInvoiceItem->eavInts) > 0) {
                    $eav_int_field_group_ids = $serviceInvoiceItem->eavInts()->pluck('field_group_id')->toArray();
                } else {
                    $eav_int_field_group_ids = [];
                }
                if (count($serviceInvoiceItem->eavDatetimes) > 0) {
                    $eav_datetime_field_group_ids = $serviceInvoiceItem->eavDatetimes()->pluck('field_group_id')->toArray();
                } else {
                    $eav_datetime_field_group_ids = [];
                }
                //GET UNIQUE FIELDGROUP IDs
                $field_group_ids = array_unique(array_merge($eav_varchar_field_group_ids, $eav_int_field_group_ids, $eav_datetime_field_group_ids));
                $field_group_val = [];
                if (!empty($field_group_ids)) {
                    foreach ($field_group_ids as $fg_key => $fg_id) {
                        // dump($fg_id);
                        $fd_varchar_array = [];
                        $fd_int_array = [];
                        $fd_main_varchar_array = [];
                        $fd_varchar_array = DB::table('eav_varchar')
                            ->where('entity_type_id', 1040)
                            ->where('entity_id', $serviceInvoiceItem->id)
                            ->where('field_group_id', $fg_id)
                            ->leftJoin('fields', 'fields.id', 'eav_varchar.field_id')
                            ->select('field_id as id', 'value', 'fields.name as field_name')
                            ->get()
                            ->toArray();
                        $fd_datetimes = DB::table('eav_datetime')
                            ->where('entity_type_id', 1040)
                            ->where('entity_id', $serviceInvoiceItem->id)
                            ->where('field_group_id', $fg_id)
                            ->leftJoin('fields', 'fields.id', 'eav_datetime.field_id')
                            ->select('field_id as id', 'value', 'fields.name as field_name')
                            ->get()
                            ->toArray();
                        $fd_datetime_array = [];
                        if (!empty($fd_datetimes)) {
                            foreach ($fd_datetimes as $fd_datetime_key => $fd_datetime_value) {
                                //DATEPICKER
                                if ($fields[$fd_datetime_value->id]->type_id == 7) {
                                    $fd_datetime_array[] = [
                                        'id' => $fd_datetime_value->id,
                                        'value' => date('d-m-Y', strtotime($fd_datetime_value->value)),
                                    ];
                                } elseif ($fields[$fd_datetime_value->id]->type_id == 8) {
                                    //DATETIMEPICKER
                                    $fd_datetime_array[] = [
                                        'id' => $fd_datetime_value->id,
                                        'value' => date('d-m-Y H:i:s', strtotime($fd_datetime_value->value)),
                                    ];
                                }
                            }
                        }
                        $fd_ints = DB::table('eav_int')
                            ->where('entity_type_id', 1040)
                            ->where('entity_id', $serviceInvoiceItem->id)
                            ->where('field_group_id', $fg_id)
                            ->leftJoin('fields', 'fields.id', 'eav_int.field_id')
                            ->select(
                                'field_id as id',
                                'fields.name as field_name',
                                DB::raw('GROUP_CONCAT(value) as value')
                            )
                            ->groupBy('field_id')
                            ->get()
                            ->toArray();
                        $fd_int_array = [];
                        if (!empty($fd_ints)) {
                            foreach ($fd_ints as $fd_int_key => $fd_int_value) {
                                //MULTISELECT DROPDOWN
                                if ($fields[$fd_int_value->id]->type_id == 2) {
                                    $fd_int_array[] = [
                                        'id' => $fd_int_value->id,
                                        'value' => explode(',', $fd_int_value->value),
                                    ];
                                } elseif ($fields[$fd_int_value->id]->type_id == 9) {
                                    //SWITCH
                                    $fd_int_array[] = [
                                        'id' => $fd_int_value->id,
                                        'value' => ($fd_int_value->value ? 'Yes' : 'No'),
                                    ];
                                } else {
                                    //OTHERS
                                    $fd_int_array[] = [
                                        'id' => $fd_int_value->id,
                                        'value' => $fd_int_value->value,
                                    ];
                                }
                            }
                        }
                        $fd_main_varchar_array = array_merge($fd_varchar_array, $fd_int_array, $fd_datetime_array);
                        //PUSH INDIVIDUAL FIELD GROUP TO ARRAY
                        $field_group_val[] = [
                            'id' => $fg_id,
                            'fields' => $fd_main_varchar_array,
                        ];
                    }
                }
                //PUSH TOTAL FIELD GROUPS
                $serviceInvoiceItem->field_groups = $field_group_val;

                if ($serviceInvoiceItem->serviceItem->subCategory->attachment) {
                    $additional_image_name = $serviceInvoiceItem->serviceItem->subCategory->attachment->name;
                    $additional_image_path = base_path('storage/app/public/service-invoice/service-item-sub-category/attachments/');
                }

            }
        }
        //dd($this->type_id);
        $type = $serviceInvoiceItem->serviceItem;
        if (!empty($type->sac_code_id) && ($this->type_id == 1060)) {
            $this->sac_code_status = 'CREDIT NOTE(CRN)';
            $this->document_type = 'CRN';
        } elseif (empty($type->sac_code_id) && ($this->type_id == 1060)) {
            $this->sac_code_status = 'FINANCIAL CREDIT NOTE';
            $this->document_type = 'CRN';
        } elseif ($this->type_id == 1061) {
            $this->sac_code_status = 'Tax Invoice(DBN)';
            $this->document_type = 'DBN';
        } else {
            $this->sac_code_status = 'Invoice(INV)';
            $this->document_type = 'INV';
        }

        if ($this->total > $this->final_amount) {
            $this->round_off_amount = number_format(($this->final_amount - $this->total), 2);
        } elseif ($this->total < $this->final_amount) {
            $this->round_off_amount;
        } else {
            $this->round_off_amount = 0;
        }
        if ($this->to_account_type_id == 1440 || $this->to_account_type_id == 1440) {
            $city = City::where('name', $this->address->city)->first();
            // dd($city);
            $state = State::find($this->address->state_id);
            $this->address->state_code = $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-';
        } else {
            $state = State::find($this->customer->primaryAddress ? $this->customer->primaryAddress->state_id : null);
            $this->customer->state_code = $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-';
            $address = Address::with(['city', 'state', 'country'])->where('address_of_id', 21)->where('entity_id', $this->customer_id)->first();
            if ($address) {
                $this->customer->address .= $address->address_line1 ? $address->address_line1 . ', ' : '';
                $this->customer->address .= $address->address_line2 ? $address->address_line2 . ', ' : '';
                $this->customer->address .= $address->city ? $address->city->name . ', ' : '';
                $this->customer->address .= $address->state ? $address->state->name . ', ' : '';
                $this->customer->address .= $address->country ? $address->country->name . ', ' : '';
                $this->customer->address .= $address->pincode ? $address->pincode . '.' : '';
            } else {
                $this->customer->address = '';
            }
        }

        // $this->customer->state_code = $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-';

        $this->qr_image = $this->qr_image ? base_path('storage/app/public/service-invoice/IRN_images/' . $this->qr_image) : null;
        $this->irn_number = $this->irn_number ? $this->irn_number : null;
        $this->ack_no = $this->ack_no ? $this->ack_no : null;
        $this->ack_date = $this->ack_date ? $this->ack_date : null;

        // dd($this->sac_code_status);
        //dd($serviceInvoiceItem->field_groups);

        $this['additional_image_name'] = $additional_image_name;
        $this['additional_image_path'] = $additional_image_path;

        $data = [];
        $data['service_invoice_pdf'] = $this;

        $tax_list = Tax::get();
        $data['tax_list'] = $tax_list;
        // dd($this->data['service_invoice_pdf']);
        $path = storage_path('app/public/service-invoice-pdf/');
        $pathToFile = $path . '/' . $this->number . '.pdf';
        File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

        $pdf = app('dompdf.wrapper');
        $pdf->getDomPDF()->set_option("enable_php", true);
        $pdf = $pdf->loadView('service-invoices/pdf/index', $data);
        // $po_file_name = 'Invoice-' . $this->number . '.pdf';
        File::delete($pathToFile);
        File::put($pathToFile, $pdf->output());
    }

    public function createServiceInvoicePdf()
    {
        // dd('test');
        $this->company->formatted_address = $this->company->primaryAddress ? $this->company->primaryAddress->getFormattedAddress() : 'NA';
        // $this->outlets->formatted_address = $this->outlets->primaryAddress ? $this->outlets->primaryAddress->getFormattedAddress() : 'NA';
        if ($this->number == 'F21MDSDN0001') {
            dump('static outlet');
            $this['branch_id'] = 134; //TRY - Trichy
            $this->outlets = $this->outlets ? $this->outlets : 'NA';
        } else {
            $this->outlets = $this->outlets ? $this->outlets : 'NA';
        }

        $this->customer->formatted_address = $this->customer->primaryAddress ? $this->customer->primaryAddress->address_line1 : 'NA';
        // $city = City::where('name', $this->customer->city)->first();
        // // dd($city);
        // $state = State::find($city->state_id);

        // dd($this->outlets->formatted_address);
        $fields = Field::withTrashed()->get()->keyBy('id');
        if (count($this->serviceInvoiceItems) > 0) {
            $array_key_replace = [];
            foreach ($this->serviceInvoiceItems as $key => $serviceInvoiceItem) {
                $taxes = $serviceInvoiceItem->taxes;
                $type = $serviceInvoiceItem->serviceItem;
                foreach ($taxes as $array_key_replace => $tax) {
                    $serviceInvoiceItem[$tax->name] = $tax;
                }
                //dd($type->sac_code_id);
            }
            //Field values
            $gst_total = 0;

            $additional_image_name = '';
            $additional_image_path = '';

            foreach ($this->serviceInvoiceItems as $key => $serviceInvoiceItem) {
                // dd($serviceInvoiceItem);
                $serviceInvoiceItem->eInvoiceUom;

                //FIELD GROUPS AND FIELDS INTEGRATION
                if (count($serviceInvoiceItem->eavVarchars) > 0) {
                    $eav_varchar_field_group_ids = $serviceInvoiceItem->eavVarchars()->pluck('field_group_id')->toArray();
                } else {
                    $eav_varchar_field_group_ids = [];
                }
                if (count($serviceInvoiceItem->eavInts) > 0) {
                    $eav_int_field_group_ids = $serviceInvoiceItem->eavInts()->pluck('field_group_id')->toArray();
                } else {
                    $eav_int_field_group_ids = [];
                }
                if (count($serviceInvoiceItem->eavDatetimes) > 0) {
                    $eav_datetime_field_group_ids = $serviceInvoiceItem->eavDatetimes()->pluck('field_group_id')->toArray();
                } else {
                    $eav_datetime_field_group_ids = [];
                }
                //GET UNIQUE FIELDGROUP IDs
                $field_group_ids = array_unique(array_merge($eav_varchar_field_group_ids, $eav_int_field_group_ids, $eav_datetime_field_group_ids));
                $field_group_val = [];
                if (!empty($field_group_ids)) {
                    foreach ($field_group_ids as $fg_key => $fg_id) {
                        // dump($fg_id);
                        $fd_varchar_array = [];
                        $fd_int_array = [];
                        $fd_main_varchar_array = [];
                        $fd_varchar_array = DB::table('eav_varchar')
                            ->where('entity_type_id', 1040)
                            ->where('entity_id', $serviceInvoiceItem->id)
                            ->where('field_group_id', $fg_id)
                            ->leftJoin('fields', 'fields.id', 'eav_varchar.field_id')
                            ->select('field_id as id', 'value', 'fields.name as field_name')
                            ->get()
                            ->toArray();
                        $fd_datetimes = DB::table('eav_datetime')
                            ->where('entity_type_id', 1040)
                            ->where('entity_id', $serviceInvoiceItem->id)
                            ->where('field_group_id', $fg_id)
                            ->leftJoin('fields', 'fields.id', 'eav_datetime.field_id')
                            ->select('field_id as id', 'value', 'fields.name as field_name')
                            ->get()
                            ->toArray();
                        $fd_datetime_array = [];
                        if (!empty($fd_datetimes)) {
                            foreach ($fd_datetimes as $fd_datetime_key => $fd_datetime_value) {
                                //DATEPICKER
                                if ($fields[$fd_datetime_value->id]->type_id == 7) {
                                    $fd_datetime_array[] = [
                                        'id' => $fd_datetime_value->id,
                                        'value' => date('d-m-Y', strtotime($fd_datetime_value->value)),
                                    ];
                                } elseif ($fields[$fd_datetime_value->id]->type_id == 8) {
                                    //DATETIMEPICKER
                                    $fd_datetime_array[] = [
                                        'id' => $fd_datetime_value->id,
                                        'value' => date('d-m-Y H:i:s', strtotime($fd_datetime_value->value)),
                                    ];
                                }
                            }
                        }
                        $fd_ints = DB::table('eav_int')
                            ->where('entity_type_id', 1040)
                            ->where('entity_id', $serviceInvoiceItem->id)
                            ->where('field_group_id', $fg_id)
                            ->leftJoin('fields', 'fields.id', 'eav_int.field_id')
                            ->select(
                                'field_id as id',
                                'fields.name as field_name',
                                DB::raw('GROUP_CONCAT(value) as value')
                            )
                            ->groupBy('field_id')
                            ->get()
                            ->toArray();
                        $fd_int_array = [];
                        if (!empty($fd_ints)) {
                            foreach ($fd_ints as $fd_int_key => $fd_int_value) {
                                //MULTISELECT DROPDOWN
                                if ($fields[$fd_int_value->id]->type_id == 2) {
                                    $fd_int_array[] = [
                                        'id' => $fd_int_value->id,
                                        'value' => explode(',', $fd_int_value->value),
                                    ];
                                } elseif ($fields[$fd_int_value->id]->type_id == 9) {
                                    //SWITCH
                                    $fd_int_array[] = [
                                        'id' => $fd_int_value->id,
                                        'value' => ($fd_int_value->value ? 'Yes' : 'No'),
                                    ];
                                } else {
                                    //OTHERS
                                    $fd_int_array[] = [
                                        'id' => $fd_int_value->id,
                                        'value' => $fd_int_value->value,
                                    ];
                                }
                            }
                        }
                        $fd_main_varchar_array = array_merge($fd_varchar_array, $fd_int_array, $fd_datetime_array);
                        //PUSH INDIVIDUAL FIELD GROUP TO ARRAY
                        $field_group_val[] = [
                            'id' => $fg_id,
                            'fields' => $fd_main_varchar_array,
                        ];
                    }
                }
                //PUSH TOTAL FIELD GROUPS
                $serviceInvoiceItem->field_groups = $field_group_val;

                if ($serviceInvoiceItem->serviceItem->subCategory->attachment) {
                    $additional_image_name = $serviceInvoiceItem->serviceItem->subCategory->attachment->name;
                    $additional_image_path = base_path('storage/app/public/service-invoice/service-item-sub-category/attachments/');
                }
            }
        }
        //dd($this->type_id);
        $type = $serviceInvoiceItem->serviceItem;
        if (!empty($type->sac_code_id) && ($this->type_id == 1060)) {
            $this->sac_code_status = 'CREDIT NOTE(CRN)';
            $this->document_type = 'CRN';
        } elseif (empty($type->sac_code_id) && ($this->type_id == 1060)) {
            $this->sac_code_status = 'FINANCIAL CREDIT NOTE';
            $this->document_type = 'CRN';
        } elseif ($this->type_id == 1061) {
            $this->sac_code_status = 'Tax Invoice(DBN)';
            $this->document_type = 'DBN';
        } else {
            $this->sac_code_status = 'Invoice(INV)';
            $this->document_type = 'INV';
        }

        if ($this->total > $this->final_amount) {
            $this->round_off_amount = number_format(($this->final_amount - $this->total), 2);
        } elseif ($this->total < $this->final_amount) {
            $this->round_off_amount;
        } else {
            $this->round_off_amount = 0;
        }
        if ($this->to_account_type_id == 1440 || $this->to_account_type_id == 1440) {
            $city = City::where('name', $this->address->city)->first();
            // dd($city);
            $state = State::find($this->address->state_id);
            $this->address->state_code = $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-';
        } else {
            $state = State::find($this->customer->primaryAddress ? $this->customer->primaryAddress->state_id : null);
            $this->customer->state_code = $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-';
            $address = Address::with(['city', 'state', 'country'])->where('address_of_id', 21)->where('entity_id', $this->customer_id)->first();
            if ($address) {
                $this->customer->address .= $address->address_line1 ? $address->address_line1 . ', ' : '';
                $this->customer->address .= $address->address_line2 ? $address->address_line2 . ', ' : '';
                $this->customer->address .= $address->city ? $address->city->name . ', ' : '';
                $this->customer->address .= $address->state ? $address->state->name . ', ' : '';
                $this->customer->address .= $address->country ? $address->country->name . ', ' : '';
                $this->customer->address .= $address->pincode ? $address->pincode . '.' : '';
            } else {
                $this->customer->address = '';
            }
        }

        // $this->customer->state_code = $state->e_invoice_state_code ? $state->name . '(' . $state->e_invoice_state_code . ')' : '-';

        $this->qr_image = $this->qr_image ? base_path('storage/app/public/service-invoice/IRN_images/' . $this->qr_image) : null;
        $this->irn_number = $this->irn_number ? $this->irn_number : null;
        $this->ack_no = $this->ack_no ? $this->ack_no : null;
        $this->ack_date = $this->ack_date ? $this->ack_date : null;

        // dd($this->sac_code_status);
        //dd($serviceInvoiceItem->field_groups);

        $this['additional_image_name'] = $additional_image_name;
        $this['additional_image_path'] = $additional_image_path;

        $data = [];
        $data['service_invoice_pdf'] = $this;

        $tax_list = Tax::get();
        $data['tax_list'] = $tax_list;
        // dd($this->data['service_invoice_pdf']);
        $path = storage_path('app/public/service-invoice-pdf/');
        $pathToFile = $path . '/' . $this->number . '.pdf';
        File::isDirectory($path) or File::makeDirectory($path, 0777, true, true);

        $pdf = app('dompdf.wrapper');
        $pdf->getDomPDF()->set_option("enable_php", true);
        $pdf = $pdf->loadView('service-invoices/pdf/index', $data);
        // $po_file_name = 'Invoice-' . $this->number . '.pdf';
        File::delete($pathToFile);
        File::put($pathToFile, $pdf->output());
    }

    public static function percentage($num, $per)
    {
        return ($num / 100) * $per;
    }

    public static function apiLogs($params)
    {
        // dd($params);
        $api_log = new ApiLog;
        $api_log->type_id = $params['type_id'];
        $api_log->entity_number = $params['entity_number'];
        $api_log->entity_id = $params['entity_id'];
        $api_log->url = $params['url'];
        $api_log->src_data = $params['src_data'];
        $api_log->response_data = $params['response_data'];
        $api_log->user_id = $params['user_id'];
        $api_log->status_id = $params['status_id'];
        $api_log->errors = $params['errors'];
        $api_log->created_by_id = $params['created_by_id'];
        $api_log->save();

        return $api_log;
    }
}
