@extends('layouts.app')
@section('title')
  Admin Users
@stop
@section('content')

<?php $parameters = request()->input(); ?>

<div class="card mb-3" id="customersTable" data-list='{"valueNames":["id","company_name","fullname","email","role"],"page":10,"pagination":true}'>
    <div class="card-header">
      <div class="row flex-between-center">
        <div class="col-4 col-sm-auto d-flex align-items-center pe-0">
          <h5 class="fs-0 mb-0 text-nowrap py-2 py-xl-0 me-3">{{ "Admin Users" }}</h5>

          <a class="btn btn-falcon-default btn-sm" href="{{ route('CreateAdminUser') }}">
            <span class="fas fa-plus" data-fa-transform="shrink-3 down-2"></span>
            <span class="d-none d-sm-inline-block ms-1">New</span></a>

            <a class="btn btn-falcon-default btn-sm ms-3" href="{{ route('AdminsCSV') }}">
              <span class="fas fa-external-link-alt" data-fa-transform="shrink-3 down-2"></span>
              <span class="d-none d-sm-inline-block ms-1">Admins CSV</span>
            </a>
        </div>
        <div class="col-8 col-sm-auto text-end ps-2">
          <div id="table-customers-replace-element">
            <form action="{{ route('AdminUser',$parameters) }}" method="get"> 
              {{ csrf_field() }}
                <div class="row no-gutters">
                  <div class="col pe-0">
                    <input class="form-control form-control-sm shadow-none" value="{{ (!empty($request->keyword)) ? $request->keyword : "" ; }}" name="keyword" type="text" placeholder="Search By First Name, Last Name, Email" >
                  </div>
                  <div class="col pe-0 ">
                    <select name="status" class="form-select form-select-sm audience-select-menu">
                      <option value="" >Status</option>
                      <option {{ (!empty($request) && $request->status == '1') ? "selected" : "" ; }} value="1">Active</option>
                      <option {{ (!empty($request) && $request->status == '2') ? "selected" : "" ; }} value="2">Inactive</option>
                    </select>
                  </div>
                  <div class="col-auto">
                    <button type="submit" class="btn btn-sm btn-primary">
                      Submit
                    </button>
                  </div>
                </div>
            </form>
          </div>
        </div>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table table-sm table-striped fs--1 mb-0 overflow-hidden">
          <thead class="bg-200 text-900">
            <tr>

              <?php if(!empty($request->type)){

                if($request->type == "asc"){
                  $type = "desc";
                }else{
                  $type = "asc";
                }
  
              }else{
                $type = "asc";
              }?>
              
              <th class="pe-1 align-middle white-space-nowrap" onclick="window.location.href='{{ request()->fullUrlWithQuery(['sorting' => 'id','type' => $type]) }}'">ID</th>
              <th class="pe-1 align-middle white-space-nowrap" onclick="window.location.href='{{ request()->fullUrlWithQuery(['sorting' => 'first_name','type' => $type]) }}'">First Name</th>
              <th class="pe-1 align-middle white-space-nowrap" onclick="window.location.href='{{ request()->fullUrlWithQuery(['sorting' => 'last_name','type' => $type]) }}'">Last Name</th>
              <th class="pe-1 align-middle white-space-nowrap" onclick="window.location.href='{{ request()->fullUrlWithQuery(['sorting' => 'email','type' => $type]) }}'">Email</th>
              <th class="pe-1 align-middle white-space-nowrap" onclick="window.location.href='{{ request()->fullUrlWithQuery(['sorting' => 'status','type' => $type]) }}'">Status</th>
              <th class="pe-1 align-middle white-space-nowrap" onclick="window.location.href='{{ request()->fullUrlWithQuery(['sorting' => 'created_at','type' => $type]) }}'">Created At</th>
              <th class="align-middle no-sort"></th>
            </tr>
          </thead>
          <tbody class="list" id="table-customers-body">
            @foreach($Users as $key => $user)
            <tr class="btn-reveal-trigger">
              <td class="id align-middle white-space-nowrap py-2">
                {{ $user->id }}
              </td>
              <td class="first_name align-middle white-space-nowrap py-2">
                  <div class="d-flex d-flex align-items-center">
                      <h5 class="mb-0 fs--1">
                        {{ $user->first_name }}
                      </h5>
                  </div>
              </td>
              <td class="last_name align-middle white-space-nowrap py-2">
                <div class="d-flex d-flex align-items-center">
                    <h5 class="mb-0 fs--1">
                      {{ $user->last_name }}
                    </h5>
                </div>
            </td>
              <td class="email align-middle white-space-nowrap py-2">
                {{ $user->email }}
              </td>
              <td class="status align-middle white-space-nowrap py-2">
                {{ ($user->status == '1') ? 'Active' : "Inactive"; }}
              </td>
              <td class="created_at align-middle white-space-nowrap py-2">
                {{ date_format(date_create($user->created_at), 'd-m-Y') }}
              </td>

              <td class="align-middle white-space-nowrap py-2 text-end">
                <div class="dropdown font-sans-serif position-static">
                  <button class="btn btn-link text-600 btn-sm dropdown-toggle btn-reveal" type="button" id="customer-dropdown-0" data-bs-toggle="dropdown" data-boundary="window" aria-haspopup="true" aria-expanded="false"><span class="fas fa-ellipsis-h fs--1"></span></button>
                  <div class="dropdown-menu dropdown-menu-end border py-0" aria-labelledby="customer-dropdown-0">
                    <div class="bg-white py-2">
                      <a class="dropdown-item text-info" href="{{ route('CreateAdminUser',$user->id) }}">Edit</a>
                      <a class="dropdown-item text-danger" href="{{ route('DeleteAdminUser',$user->id) }}" onClick="return confirm('Are you sure you want to delete?')">Delete</a>
                      {{-- <a class="dropdown-item text-danger" href="#!">Delete</a> --}}
                    </div>
                  </div>
                </div>
              </td>
            </tr>
            @endforeach
          </tbody>
        </table>
      </div>
    </div>
    <div class="row align-items-center mt-3 mb-3 px-3">
      <div class="pagination d-none"></div>
      <div class="col-auto d-flex">
        <div class="input-group input-group-sm">
          <span class="input-group-text" id="basic-addon1">Show</span>
          <select aria-describedby="basic-addon1" class="form-select form-select-sm audience-select-menu" onchange="if (this.value) window.location.href=this.value">
            <option {{ ($paginate == '25')  ? "selected" : "" ; }} value="{{ request()->fullUrlWithQuery(['paginate' => '25']) }}" >25</option>
            <option {{ ($paginate == '100') ? "selected" : "" ; }} value="{{ request()->fullUrlWithQuery(['paginate' => '100']) }}" >100</option>
            <option {{ ($paginate == '500') ? "selected" : "" ; }} value="{{ request()->fullUrlWithQuery(['paginate' => '500']) }}" >500</option>
            <option {{ ($paginate == $Users->total())  ? "selected" : "" ; }} value="{{ request()->fullUrlWithQuery(['paginate' => $Users->total()]) }}" >ALL</option> 
          </select>
          {{-- <span class="input-group-text" id="basic-addon2">entries</span> --}}
        </div>
      </div>
      <div class="col">
        <p class="mb-0 fs--1">
          <span class="d-none d-sm-inline-block" >{{ $Users->firstItem() }} to {{ $Users->lastItem() }} of {{ $Users->total() }}</span>
        </p>
      </div>
      <div class="col-auto d-flex ">
        @php 
          ($Users->currentPage() > 1) ? $prev = $Users->currentPage() - 1 : $prev = "1" ;
          ($Users->currentPage() == $Users->lastPage()) ? $next = $Users->currentPage() : $next = $Users->currentPage() + 1 ; 
        
        @endphp
        <a class="btn btn-sm btn-primary" href="{{ request()->fullUrlWithQuery(['page' => $prev])  }}" ><span>Previous</span></a>
        <a class="btn btn-sm btn-primary px-4 ms-2" href="{{ request()->fullUrlWithQuery(['page' => $next]) }}" ><span>Next</span></a>
      </div>
    </div>
  </div>

@endsection

@section('script')
    <script>
      var options = {
        valueNames: ["id","first_name","last_name","email","created_at"]
      };

      var userList = new List('customersTable', options);    
  </script>
@endsection
