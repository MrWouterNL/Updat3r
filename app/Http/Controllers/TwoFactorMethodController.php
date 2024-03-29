<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTwoFactorMethodRequest;
use App\Http\Requests\UpdateTwoFactorMethodRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Providers\RouteServiceProvider;
use Exception;
use Illuminate\Support\Arr;
use Bitbeans\Yubikey\YubikeyFacade as Yubikey;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use PragmaRX\Google2FAQRCode\Google2FA;
use App\Models\TwoFactorMethod;
use App\Models\User;
use Illuminate\Http\Request;

class TwoFactorMethodController extends Controller
{
    public function __construct() {
        $this->middleware('auth.2fa')->only(['update', 'destroy']);
    }

    /**
     * Display a listing of the resource.
     *
     * @param User $user
     * @return Application|Factory|View
     */
    public function index(User $user)
    {
        $google2fa = new Google2FA();
        $two_factor_secret = $google2fa->generateSecretKey(32);
        $qrCodeUrl = $google2fa->getQRCodeInline(
            config('app.name'),
            $user->email,
            $two_factor_secret
        );

        return view('2fa.index')->with([
            'user' => $user,
            'two_factor_secret' => $two_factor_secret,
            'two_factor_image' => $qrCodeUrl,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreTwoFactorMethodRequest $request
     * @param User $user
     * @return RedirectResponse
     */
    public function store(StoreTwoFactorMethodRequest $request, User $user)
    {
        $data = $request->validated();

        if (Arr::has($data, 'yubikey_otp')) {
            try {
                // Validate yubikey with a timeout of max. 2 seconds
                Yubikey::verify($data['yubikey_otp'], null, false, null, 2);

                $prefix = Yubikey::parsePasswordOTP($data['yubikey_otp'])['prefix'];
                $twoFactorMethod = new TwoFactorMethod([
                    'name' => $data['name'],
                    'yubikey_otp' => $prefix
                ]);
                $user->twoFactorMethods()->save($twoFactorMethod);
                $request->session()->flash('success', 'Successfully added a new 2FA method');

                // Update session to prevent user needing to type in OTP
                $request->session()->put('2fa_method', $twoFactorMethod->id);
            } catch (Exception $exception) {
                if ($exception->getMessage() == 'REPLAYED_OTP') {
                    $request->session()->flash('error', 'The supplied OTP has been used before.');
                }
                $request->session()->flash('error', 'Invalid OTP supplied. Please try again!');
            }
        } else {
            $google2fa = new Google2FA();

            if ($google2fa->verifyKey($data['two_factor_secret'], $data['two_factor_check'], 8)) {
                $twoFactorMethod = new TwoFactorMethod([
                    'name' => $data['name'],
                    'google2fa_secret' => $data['two_factor_secret']
                ]);
                $user->twoFactorMethods()->save($twoFactorMethod);
                $request->session()->flash('success', 'Successfully added a new 2FA method');

                // Update session to prevent user needing to type in OTP
                $request->session()->put('2fa_method', $twoFactorMethod->id);
            } else {
                $request->session()->flash('error', 'Invalid 2FA code supplied. Please try again!');
            }
        }
        return redirect()->route('users.twofactormethods.index', ['user' => $user]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param UpdateTwoFactorMethodRequest $request
     * @param User $user
     * @param TwoFactorMethod $twofactormethod
     * @return RedirectResponse
     */
    public function update(UpdateTwoFactorMethodRequest $request, User $user, TwoFactorMethod $twofactormethod)
    {
        $data = $request->validated();
        if ($twofactormethod->update($data)) {
            $request->session()->flash('success', 'Successfully changed this 2FA method.');

            // Update session to prevent user needing to type in OTP
            $request->session()->put('2fa_method', $twofactormethod->id);
        } else {
            $request->session()->flash('error', 'Something went wrong when changing this 2FA method.');
        }
        return redirect()->route('users.twofactormethods.index', ['user' => $user]);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Request $request
     * @param User $user
     * @param TwoFactorMethod $twofactormethod
     * @return RedirectResponse
     */
    public function destroy(Request $request, User $user, TwoFactorMethod $twofactormethod)
    {
        if ($twofactormethod->delete()) {
            $request->session()->flash('success', 'Successfully deleted this 2FA method.');
        } else {
            $request->session()->flash('error', 'Something went wrong whilst deleting this 2FA method.');
        }
        return redirect()->route('users.twofactormethods.index', ['user' => $user]);
    }

    /**
     * Verify the 2FA OTP
     *
     * @param VerifyOtpRequest $request
     * @return RedirectResponse
     */
    public function verify_otp(VerifyOtpRequest $request)
    {
        $data = $request->validated();

        $twoFactorMethods = $request->user()->twoFactorMethods()
            ->select(['id', 'google2fa_secret', 'yubikey_otp'])
            ->where('enabled', true)
            ->get();

        $google2fa = new Google2FA();
        $correctOtps = $twoFactorMethods->filter(function ($twoFactorMethod) use ($google2fa, $data) {
            if ($twoFactorMethod->google2fa_secret != null) {
                return $google2fa->verifyKey($twoFactorMethod->google2fa_secret, $data['otp'], 2);
            } else {
                $parsedOtp = Yubikey::parsePasswordOTP($data['otp']);
                if ($parsedOtp && $twoFactorMethod->yubikey_otp == $parsedOtp['prefix']) {
                    try {
                        // Validate yubikey with a timeout of max. 2 seconds
                        return Yubikey::verify($data['otp'], null, false, null, 2);
                    } catch (Exception) {
                        return false;
                    }
                }
            }
            return false;
        });

        if ($correctOtps->count() == 0) {
            return redirect(RouteServiceProvider::HOME)->with(['error' => 'This OTP is not valid.']);
        }

        $request->session()->put('2fa_method', $correctOtps->first()->id);
        return redirect(RouteServiceProvider::HOME);
    }
}
