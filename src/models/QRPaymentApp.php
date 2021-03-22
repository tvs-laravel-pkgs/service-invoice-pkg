<?php

namespace Abs\ServiceInvoicePkg;
use App\Company;
use App\Sbu;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class QRPaymentApp extends Model {
	use SoftDeletes;
	protected $table = 'qr_payment_apps';
	protected $fillable = [
		'created_by_id',
		'updated_by_id',
		'deleted_by_id',
	];
	public function company() {
		return $this->belongsTo('Abs\BusinessPkg\Company', 'company_id', 'id');
	}
	public function sbu() {
		return $this->belongsTo('Abs\BusinessPkg\Sbu', 'sbu_id', 'id');
	}
	public function referenceCompany() {
		return $this->belongsTo('Abs\BusinessPkg\Company', 'reference_company', 'id');
	}
}
