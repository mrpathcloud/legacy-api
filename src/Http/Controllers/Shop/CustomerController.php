<?php

namespace Mrpath\API\Http\Controllers\Shop;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Mrpath\Customer\Http\Requests\CustomerRegistrationRequest;
use Mrpath\Customer\Repositories\CustomerGroupRepository;
use Mrpath\Customer\Repositories\CustomerRepository;
use Mrpath\API\Http\Resources\Customer\Customer as CustomerResource;
use Illuminate\Support\Facades\Hash;

class CustomerController extends Controller
{
    /**
     * Contains current guard
     *
     * @var array
     */
    protected $guard;

    /**
     * Contains route related configuration
     *
     * @var array
     */
    protected $_config;

    /**
     * Repository object
     *
     * @var \Mrpath\Customer\Repositories\CustomerRepository
     */
    protected $customerRepository;

    /**
     * Repository object
     *
     * @var \Mrpath\Customer\Repositories\CustomerGroupRepository
     */
    protected $customerGroupRepository;

    /**
     * Create a new controller instance.
     *
     * @param  \Mrpath\Customer\Repositories\CustomerRepository  $customerRepository
     * @param  \Mrpath\Customer\Repositories\CustomerGroupRepository  $customerGroupRepository
     * @return void
     */
    public function __construct(
        CustomerRepository $customerRepository,
        CustomerGroupRepository $customerGroupRepository
    )   {
        $this->guard = request()->has('token') ? 'api' : 'customer';

        $this->_config = request('_config');

        if (isset($this->_config['authorization_required']) && $this->_config['authorization_required']) {

            auth()->setDefaultDriver($this->guard);

            $this->middleware('auth:' . $this->guard);
        }
        
        $this->middleware('validateAPIHeader');

        $this->customerRepository = $customerRepository;

        $this->customerGroupRepository = $customerGroupRepository;
    }

    /**
     * Method to store user's sign up form data to DB.
     *
     * @return \Illuminate\Http\Response
     */
    public function create(CustomerRegistrationRequest $request)
    {
        $request->validated();

        $data = [
            'first_name'  => $request->get('first_name'),
            'last_name'   => $request->get('last_name'),
            'email'       => $request->get('email'),
            'password'    => $request->get('password'),
            'password'    => bcrypt($request->get('password')),
            'channel_id'  => core()->getCurrentChannel()->id,
            'is_verified' => 1,
            'customer_group_id' => $this->customerGroupRepository->findOneWhere(['code' => 'general'])->id
        ];

        Event::dispatch('customer.registration.before');

        $customer = $this->customerRepository->create($data);

        Event::dispatch('customer.registration.after', $customer);
        
        if ( core()->getConfigData('general.api.customer.login_after_register') ) {
            $jwtToken = null;

            if (! $jwtToken = auth()->guard($this->guard)->attempt($request->only(['email', 'password']))) {
                return response()->json([
                    'error' => 'Invalid Email or Password',
                ], 200);
            }

            Event::dispatch('customer.after.login', $request->get('email'));

            return response()->json([
                'token'   => $jwtToken,
                'message' => 'Logged in successfully.',
                'data'    => new CustomerResource($customer),
            ]);
        } else {
            
            return response()->json([
                'message' => 'Your account has been created successfully.',
            ]);
        }
    }

    /**
     * Returns a current user data.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function get($id)
    {
        if (Auth::user($this->guard)->id === (int) $id) {
            return new $this->_config['resource'](
                $this->customerRepository->findOrFail($id)
            );
        }

        return response()->json([
            'message' => 'Invalid Request.',
        ], 403);
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return \Illuminate\Http\Response
     */
    public function destroy()
    {
        $customer = auth($this->guard)->user();

        $this->validate(request(), [
            'password'  => 'required',
        ]);

        $data = request()->all();

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => 'Warning: You need to login first to remove account.',
            ]);
        }

        if ( Hash::check($data['password'], $customer->password)) {
            
            if ($this->customerRepository->delete($customer->id)) {
                return response()->json([
                    'success'   => true,
                    'message'   => 'Success: Your account has been deleted successfully.'
                ]);
            }
        } else {
            return response()->json([
                'success'   => false,
                'message'   => 'Warning: You provided wrong current password.'
            ]);
        }
    }
}
