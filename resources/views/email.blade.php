<table style="table-layout:fixed;background-color:#F9F9F9;" id="bodyTable" width="100%" cellspacing="0" cellpadding="0" border="0">
   <tbody>
      <tr>
         <td style="padding-right:10px;padding-left:10px;" id="bodyCell" valign="top" align="center">
            <table style="max-width:600px;" class="wrapperWebview" width="100%" cellspacing="0" cellpadding="0" border="0">
               <tbody>
                  <tr>
                     <td valign="top" align="center">
                        <table width="100%" cellspacing="0" cellpadding="0" border="0">
                           <tbody>
                              <tr>
                                 <td style="padding-top: 20px; padding-right: 0px;" class="webview" valign="middle" align="right">
                                    <!-- Email View in Browser // -->
                                    <a class="text hideOnMobile" href="#" target="_blank" style="color:#777777; font-family:'Open Sans', Helvetica, Arial, sans-serif; font-size:12px; font-weight:400; font-style:normal; letter-spacing:normal; line-height:20px; text-transform:none; text-align:right; text-decoration:underline; padding:0; margin:0"></a>
                                 </td>
                              </tr>
                           </tbody>
                        </table>
                     </td>
                  </tr>
               </tbody>
            </table>
            <table style="max-width:600px;" class="wrapperWebview" width="100%" cellspacing="0" cellpadding="0" border="0">
               <tbody>
                  <tr>
                     <td valign="top" align="center">
                        <table width="100%" cellspacing="0" cellpadding="0" border="0">
                           <tbody>
                              <tr>
                                 <td style="padding-top: 40px; padding-bottom: 40px;" class="emailLogo" valign="middle" align="center">
                                    <a href="#" target="_blank" style="text-decoration:none;" class="">
                                    <h1 style="font-size: 45px;color: #00587b;margin: 0;">Flatmeta.io</h1>
                                 </td>
                              </tr>
                           </tbody>
                        </table>
                     </td>
                  </tr>
               </tbody>
            </table>
            <table style="max-width:600px;" class="wrapperBody" width="100%" cellspacing="0" cellpadding="0" border="0">
               <tbody>
                  <tr>
                     <td valign="top" align="center">
                        <table style="background-color:#005071;border-color:#005071; border-style:solid; border-width:0 1px 1px 1px;border-radius:5px" class="tableCard" width="100%" cellspacing="0" cellpadding="0" border="0">
                           <tbody>
                              <tr>
                                 <!-- Header Top Border // -->
                                 <td style=" font-size: 1px; line-height: 3px;" class="topBorder" height="3">&nbsp;</td>
                              </tr>
                              <tr>
                                 <td style="padding-bottom: 20px;" class="imgHero" valign="top" align="center">
                                    <!-- Hero Image // -->
                                    <a href="#" target="_blank" style="text-decoration:none;" class="">
                                    <img src="" alt="" style="width: 100%; max-width: 598px; height: auto; display: block;" class="" width="598" border="0">
                                    </a>
                                 </td>
                              </tr>
                              <tr>
                                 <td style="padding-bottom: 5px; padding-left: 20px; padding-right: 20px;" class="mainTitle" valign="top" align="center">
                                    <!-- Main Title Text // -->
                                    <h2 class="text" style="color: #fff; font-family: Poppins, Helvetica, Arial, sans-serif; font-size: 24px; font-weight: 500; font-style: normal; letter-spacing: normal; line-height: 36px; text-transform: none; text-align: center; padding: 0px; margin: 0px;" data-font="active">
                                        {{ $details['heading'] }}
                                    </h2>
                                 </td>
                              </tr>
                              <?php if(!empty($details['text_one'])){?>
                              <tr>
                                 <td style="padding-bottom: 30px; padding-left: 20px; padding-right: 20px;" class="subTitle" valign="top" align="center">
                                    <!-- Sub Title Text // -->
                                    <h4 class="text" style="color:#fff; font-family:'Poppins', Helvetica, Arial, sans-serif; font-size:14px; font-weight:500; font-style:normal; letter-spacing:normal; line-height:24px; text-transform:none; text-align:center; padding:0; margin:0">
                                        {{ $details['text_one'] }}
                                    </h4>
                                 </td>
                              </tr>
                              <?php }?>
                             
                              <tr>
                                 <td style="padding-left:20px;padding-right:20px;" class="containtTable ui-sortable" valign="top" align="center">
                                    <table class="tableDescription" style="" width="100%" cellspacing="0" cellpadding="0" border="0">
                                       <tbody>
                                          <tr>
                                             <td style="padding-bottom: 20px;" class="description" valign="top" align="center">
                                                <!-- Description Text// -->
                                                <p class="text" style="color:#fff; font-family:'Open Sans', Helvetica, Arial, sans-serif; font-size:14px; font-weight:400; font-style:normal; letter-spacing:normal; line-height:22px; text-transform:none; text-align:center; padding:0; margin:0"></p>
                                             </td>
                                          </tr>
                                       </tbody>
                                    </table>
                                    <?php if(!empty($details['button_text'])){?>
                                    <table class="tableMediumTitle" style="" width="100%" cellspacing="0" cellpadding="0" border="0">
                                       <tbody>
                                          <tr>
                                             <td style="padding-bottom: 20px;" class="mediumTitle" valign="top" align="center">
                                                <!-- Medium Title Text // -->
                                                <p style="font-family: Poppins, Helvetica, Arial, sans-serif; font-size: 30px; font-weight: 700; font-style: normal; letter-spacing: normal; line-height: 18px; text-transform: none; text-align: center;padding: 10px;margin: 0px;text-decoration: none;border-radius: 5px;color:#fff">
                                                   Your code is: {{ $details['button_text'] }}
                                                </p>
                                             </td>
                                          </tr>
                                       </tbody>
                                    </table>
                                    <?php }?>

                                    <table class="tableDescription" style="" width="100%" cellspacing="0" cellpadding="0" border="0">
                                       <tbody>
                                          <tr>
                                             <td style="padding-bottom: 20px;" class="description" valign="top" align="center">
                                                <!-- Description Text// -->
                                             </td>
                                          </tr>
                                       </tbody>
                                    </table>
                                    <table class="tableDescription" style="" width="100%" cellspacing="0" cellpadding="0" border="0">
                                       <tbody>
                                          <tr>
                                             <td style="padding-bottom: 20px;" class="description" valign="top" align="center">
                                                <!-- Description Text// -->
                                                <p class="text" style="color:#fff; font-family:'Open Sans', Helvetica, Arial, sans-serif; font-size:14px; font-weight:400; font-style:normal; letter-spacing:normal; line-height:22px; text-transform:none; text-align:center; padding:0; margin:0"></p>
                                             </td>
                                          </tr>
                                       </tbody>
                                    </table>
                                 </td>
                              </tr>

                              <?php if(!empty($details['text_two'])){?>
                                 <tr>
                                    <td style="padding-bottom: 30px; padding-left: 20px; padding-right: 20px;" class="subTitle" valign="top" align="center">
                                       <!-- Sub Title Text // -->
                                       <h4 class="text" style="color:#999999; font-family:'Poppins', Helvetica, Arial, sans-serif; font-size:14px; font-weight:500; font-style:normal; letter-spacing:normal; line-height:24px; text-transform:none; text-align:center; padding:0; margin:0">
                                           {{ $details['text_two'] }}
                                       </h4>
                                    </td>
                                 </tr>
                                 <?php }?>
                                 <?php if(!empty($details['text_three'])){?>
                                 <tr>
                                    <td style="padding-bottom: 30px; padding-left: 20px; padding-right: 20px;" class="subTitle" valign="top" align="center">
                                       <!-- Sub Title Text // -->
                                       <h4 class="text" style="color:#999999; font-family:'Poppins', Helvetica, Arial, sans-serif; font-size:14px; font-weight:500; font-style:normal; letter-spacing:normal; line-height:24px; text-transform:none; text-align:center; padding:0; margin:0">
                                           {{ $details['text_three'] }}
                                       </h4>
                                    </td>
                                 </tr>
                                 <?php }?>
                              <tr>
                                 <td style="font-size:1px;line-height:1px;" height="20">&nbsp;</td>
                              </tr>
                           </tbody>
                        </table>
                        <table class="space" width="100%" cellspacing="0" cellpadding="0" border="0">
                           <tbody>
                              <tr>
                                 <td style="font-size:1px;line-height:1px;" height="30">&nbsp;</td>
                              </tr>
                           </tbody>
                        </table>
                     </td>
                  </tr>
               </tbody>
            </table>
            <table style="max-width:600px;" class="wrapperFooter" width="100%" cellspacing="0" cellpadding="0" border="0">
               <tbody>
                  <tr>
                     <td valign="top" align="center">
                        <table class="footer" width="100%" cellspacing="0" cellpadding="0" border="0">
                           <tbody>
                              <tr>
                                 <td style="padding: 0px 10px 20px;" class="footerLinks" valign="top" align="center">
                                    <p class="text" style="color:#777777; font-family:'Open Sans', Helvetica, Arial, sans-serif; font-size:12px; font-weight:400; font-style:normal; letter-spacing:normal; line-height:20px; text-transform:none; text-align:center; padding:0; margin:0;">
                                       <a href="{{ env('WEB_URL') }}" style="color:#777777;text-decoration:underline;" target="_blank">
                                          {{ env('WEB_URL') }}
                                       </a>  
                                    </p>
                                 </td>
                              </tr>
                              <tr>
                                 <td style="padding: 0px 10px 10px;" class="footerEmailInfo" valign="top" align="center">
                                    <p class="text" style="color:#000; font-family:'Open Sans', Helvetica, Arial, sans-serif; font-size:12px; font-weight:400; font-style:normal; letter-spacing:normal; line-height:20px; text-transform:none; text-align:center; padding:0; margin:0;">
                                       if you have any questions contact us on <a href="mailto:info@Flatmeta.io"  style="color:#777777;text-decoration:underline;" target="_blank">info@Flatmeta.io</a><br> 
                                    </p>
                                 </td>
                              </tr>
                              <tr>
                                 <td style="font-size:1px;line-height:1px;" height="30">&nbsp;</td>
                              </tr>
                           </tbody>
                        </table>
                     </td>
                  </tr>
                  <tr>
                     <td style="font-size:1px;line-height:1px;" height="30">&nbsp;</td>
                  </tr>
               </tbody>
            </table>
         </td>
      </tr>
   </tbody>
</table>