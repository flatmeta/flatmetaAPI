<nav class="navbar navbar-light navbar-vertical navbar-expand-xl">
  <script>
     var navbarStyle = localStorage.getItem("navbarStyle");
     if (navbarStyle && navbarStyle !== 'transparent') {
         document.querySelector('.navbar-vertical').classList.add(`navbar-${navbarStyle}`);
     }
  </script>
  <div class="d-flex align-items-center">
     <div class="toggle-icon-wrapper">
        <button class="btn navbar-toggler-humburger-icon navbar-vertical-toggle" data-bs-toggle="tooltip" data-bs-placement="left" title="Toggle Navigation">
        <span class="navbar-toggle-icon">
        <span class="toggle-line"></span>
        </span>
        </button>
     </div>
     <a class="navbar-brand" href="">
        <div class="d-flex align-items-center py-3">
           Flatmeta
        </div>
     </a>
  </div>
  <div class="collapse navbar-collapse" id="navbarVerticalCollapse">
     <div class="navbar-vertical-content scrollbar">
        <ul class="navbar-nav flex-column mb-3" id="navbarVerticalNav">
           <li class="nav-item">
              <a class="nav-link " href="{{ route('home') }}">
                 <div class="d-flex align-items-center">
                    <span class="nav-link-icon"><span class="fas fa-chart-pie"></span></span>
                    <span class="nav-link-text ps-1">Dashboard</span>
                 </div>
              </a>
           </li>
           <li class="nav-item">
              <!-- label-->
              <div class="row navbar-vertical-label-wrapper mt-3 mb-2">
                 <div class="col-auto navbar-vertical-label">
                    App
                 </div>
                 <div class="col ps-0">
                    <hr class="mb-0 navbar-vertical-divider">
                 </div>
              </div>
              <!-- parent pages-->
              <a class="nav-link dropdown-indicator" href="#Users" role="button" data-bs-toggle="collapse" aria-expanded="false" aria-controls="Users">
                 <div class="d-flex align-items-center">
                    <span class="nav-link-icon">
                      <span class="fas fa-user"></span>
                    </span>
                    <span class="nav-link-text ps-1">
                      Users
                    </span>
                 </div>
              </a>
              <ul class="nav collapse" id="Users">
                 <li class="nav-item">
                    <a class="nav-link " href="{{ route('Users') }}" data-bs-toggle=""
                       aria-expanded="false">
                       <div class="d-flex align-items-center"><span class="nav-link-text ps-1">All Users</span>
                       </div>
                    </a>
                    <!-- more inner pages-->
                 </li>
                 <li class="nav-item">
                    <a class="nav-link " href="{{ route('CreateUser') }}" data-bs-toggle=""
                       aria-expanded="false">
                       <div class="d-flex align-items-center"><span class="nav-link-text ps-1">Add User</span>
                       </div>
                    </a>
                    <!-- more inner pages-->
                 </li>
              </ul>
              <!-- parent pages-->
              <a class="nav-link dropdown-indicator" href="#admins" role="button"
                 data-bs-toggle="collapse" aria-expanded="false" aria-controls="admins">
                 <div class="d-flex align-items-center"><span class="nav-link-icon"><span
                    class="fas fa-user-shield"></span></span><span
                    class="nav-link-text ps-1">Subscriptions</span>
                 </div>
              </a>
               <ul class="nav collapse" id="admins">
                  <li class="nav-item">
                     <a class="nav-link " href="{{ route('Subscriptions') }}" data-bs-toggle=""
                       aria-expanded="false">
                       <div class="d-flex align-items-center"><span class="nav-link-text ps-1">Subscriptions</span>
                       </div>
                     </a>
                    <!-- more inner pages-->
                  </li>
               </ul>


               <a class="nav-link " href="{{ route('ReportText') }}" aria-controls="admins">
               <div class="d-flex align-items-center"><span class="nav-link-icon"><span
                  class="fas fa-user-shield"></span></span><span
                  class="nav-link-text ps-1">Report Text</span>
               </div>
            </a>
           </li>
        </ul>
     </div>
  </div>
</nav>
