<!DOCTYPE html>
<html lang="en-US" >
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- ===============================================-->
    <!--    Document Title-->
    <!-- ===============================================-->
    
    @include('partials.head')
    @yield('css')
  </head>
  <body>

    <!-- ===============================================-->
    <!--    Main Content-->
    <!-- ===============================================-->
    <main class="main" id="top">
      <div class="container" data-layout="container">
        @include('partials.sidebar')
        <div class="content">
            @include('partials.topbar')
            @yield('content')
            @include('partials.footer')
        </div>
      </div>
    </main>
    <!-- ===============================================-->
    <!--    End of Main Content-->
    <!-- ===============================================-->
   
   @include('partials.footerscript')
   @yield('script')
  </body>
</html>