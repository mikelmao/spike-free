<?php

use Illuminate\Support\Facades\Route;
use Opcodes\Spike\Http\Controllers\BillingController;
use Opcodes\Spike\Http\Controllers\LicensesController;
use Opcodes\Spike\Http\Controllers\PurchasesController;
use Opcodes\Spike\Http\Controllers\SubscribeController;
use Opcodes\Spike\Http\Controllers\UsageController;

Route::get('/', fn () => redirect(route('spike.usage')));

Route::get('usage', [UsageController::class, 'index'])->name('spike.usage');

Route::get('buy', [PurchasesController::class, 'index'])->name('spike.purchase');
Route::get('buy/validate/{cart}', [PurchasesController::class, 'validateCart'])->name('spike.purchase.validate-cart');
Route::get('thank-you/{cart}', [PurchasesController::class, 'success'])->name('spike.purchase.thank-you');

Route::get('subscribe', [SubscribeController::class, 'index'])->name('spike.subscribe');
Route::get('incomplete-payment', [SubscribeController::class, 'incompletePayment'])->name('spike.subscribe.incomplete-payment');

Route::get('licenses', [LicensesController::class, 'index'])->name('spike.licenses');

Route::get('invoices', [BillingController::class, 'index'])->name('spike.invoices');
Route::get('invoices/download/{id}', [BillingController::class, 'downloadInvoice'])->name('spike.invoices.download-invoice');
