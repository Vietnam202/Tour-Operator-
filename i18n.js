'use strict';
(function(){
 const vi={
 'HOME':'TRANG CHỦ','WORKSPACES':'KHÔNG GIAN LÀM VIỆC','SHARED':'DÙNG CHUNG','MANAGEMENT':'QUẢN LÝ','SYSTEM':'HỆ THỐNG',
 'Tour Workspace':'Hồ sơ tour','Tour Library':'Kho chương trình','Sales & B2B':'Sales & B2B','Business settings':'Thiết lập doanh nghiệp','Dashboard':'Tổng quan','CRM / Customers':'CRM / Khách hàng','Tour Program Library':'Kho chương trình tour','Suppliers & Rates':'Nhà cung cấp & bảng giá','Apps & Integrations':'Ứng dụng & kết nối','Reports':'Báo cáo','✦ Ask VTA AI':'✦ Hỏi VTA AI','More':'Thêm',

 'TOUR PROGRAM LIBRARY':'KHO CHƯƠNG TRÌNH TOUR','Tour library':'Kho tour','SUPPLIER VAULT & RATES':'NHÀ CUNG CẤP & BẢNG GIÁ','← Operations':'← Điều hành',
 'Today':'Hôm nay','Sales':'Kinh doanh','Bookings':'Đặt dịch vụ','Operations':'Điều hành','Finance':'Tài chính','Suppliers':'Nhà cung cấp','Rates':'Bảng giá','Documents':'Tài liệu','Settings':'Cài đặt','Inventory':'Kho chương trình','Lead Hub':'Khách hàng tiềm năng',
 'TODAY':'HÔM NAY','SALES':'KINH DOANH','BOOKINGS':'ĐẶT DỊCH VỤ','OPERATIONS':'ĐIỀU HÀNH','FINANCE':'TÀI CHÍNH','SUPPLIERS':'NHÀ CUNG CẤP','RATES':'BẢNG GIÁ','DOCUMENTS':'TÀI LIỆU','SETTINGS':'CÀI ĐẶT','TOUR INVENTORY':'KHO CHƯƠNG TRÌNH','LEAD HUB':'KHÁCH HÀNG TIỀM NĂNG',
 'INFO':'THÔNG TIN','SCHEDULE':'LỊCH TRÌNH','COST':'CHI PHÍ','PRICE':'GIÁ BÁN','SEND':'GỬI',
 'Save':'Lưu','Cancel':'Hủy','Close':'Đóng','Delete':'Xóa','Remove':'Xóa','Duplicate':'Nhân bản','Up':'Lên','Down':'Xuống','Refresh':'Làm mới','Edit':'Sửa','Confirm':'Xác nhận','Search':'Tìm kiếm','Logout':'Đăng xuất','Sign In':'Đăng nhập','Email':'Email','Password':'Mật khẩu',
 'Tour Name':'Tên chương trình','Start Date':'Ngày bắt đầu','End Date':'Ngày kết thúc','Adults':'Người lớn','Children':'Trẻ em','Infants':'Em bé','Total Guests':'Tổng khách','Paying Pax':'Khách trả tiền','Hotel Level':'Hạng khách sạn','Tour Type':'Loại tour','Guide Language':'Ngôn ngữ hướng dẫn','Meals':'Bữa ăn',
 'Save & Continue':'Lưu và tiếp tục','New Inquiry':'Yêu cầu mới','Create Inquiry':'Tạo yêu cầu','Create Quote':'Tạo báo giá','Inquiries':'Yêu cầu','Quotes':'Báo giá','New Quote Version':'Phiên bản báo giá mới','1. Info':'1. Thông tin','2. Schedule':'2. Lịch trình','3. Cost':'3. Chi phí','4. Pricing':'4. Giá bán','5. Quality Check & Send':'5. Kiểm tra và gửi',
 'Quick Paste':'Dán nhanh','Upload DOCX / PDF / TXT':'Tải DOCX / PDF / TXT','Add Day':'Thêm ngày','Date':'Ngày','Route / Title':'Tuyến / Tiêu đề','Detailed Description':'Mô tả đầy đủ','Overnight':'Nghỉ đêm','Notes':'Ghi chú','Save Schedule & Continue':'Lưu lịch trình và tiếp tục',
 'Import itinerary':'Nhập lịch trình','Paste day-by-day itinerary':'Dán lịch trình từng ngày','Extract DRAFT preview':'Trích xuất bản xem trước DRAFT','DRAFT preview':'Bản xem trước DRAFT','Apply preview':'Áp dụng bản xem trước','Append days':'Thêm vào cuối','Replace days':'Thay thế các ngày','Source text':'Văn bản gốc','Review before applying. Nothing has been saved.':'Kiểm tra trước khi áp dụng. Chưa lưu dữ liệu.',
 'Review all descriptions, meals, overnight locations and dates before applying.':'Kiểm tra mô tả, bữa ăn, nơi nghỉ đêm và ngày trước khi áp dụng.',
 'Text before the first day remains in the source preview.':'Phần trước ngày đầu tiên được giữ trong văn bản gốc.',
 'Source day numbers are not sequential; preview has been renumbered.':'Số ngày gốc không liên tục; bản xem trước đã đánh lại số.',
 'No itinerary days yet':'Chưa có ngày trong lịch trình','Service':'Dịch vụ','Pax':'Số khách / đơn vị','Qty':'Số lượng','Unit Price':'Đơn giá','Total':'Thành tiền','Source':'Nguồn','Currency':'Tiền tệ','Category':'Danh mục','Charge basis':'Cơ sở tính phí','Review reason':'Lý do rà soát','Reviewed for current dates':'Đã rà soát cho ngày đi hiện tại',
 'PAYING_PAX':'Khách trả tiền','TOTAL_GUESTS':'Tổng khách','PER_PAX':'Theo khách','PER_ROOM_NIGHT':'Theo phòng / đêm','PER_VEHICLE':'Theo xe','PER_TRANSFER':'Theo lượt xe','PER_DAY':'Theo ngày','PER_GUIDE_DAY':'Theo hướng dẫn / ngày','PER_CABIN':'Theo cabin','PER_GROUP':'Theo đoàn','PER_SERVICE':'Theo dịch vụ',
 'Add Cost Item':'Thêm chi phí','Match Approved Rate':'Tìm giá đã duyệt','Excel Paste':'Dán từ Excel','Preview rows':'Xem trước các dòng','Save all rows':'Lưu tất cả dòng','Save row':'Lưu dòng','Duplicate row':'Nhân bản dòng','Refresh rate':'Làm mới giá','Convert to manual':'Chuyển sang giá nhập tay','Continue to Price':'Tiếp tục đến giá bán',
 'Needs Review':'Cần rà soát','DRAFT':'Bản nháp','APPROVED':'Đã duyệt','SENT':'Đã gửi','CONFIRMED':'Đã xác nhận','SUPERSEDED':'Đã thay thế','MANUAL':'Nhập tay','LEGACY':'Giá sao chép','APPROVED RATE':'Giá đã duyệt',
 'Clone & Reuse':'Nhân bản / Tái sử dụng','Target inquiry':'Yêu cầu đích','Program Only':'Chỉ chương trình','Program + Cost Structure':'Chương trình và cơ cấu chi phí','Full Quote Draft':'Toàn bộ báo giá nháp','Create DRAFT clone':'Tạo bản sao DRAFT','Document language':'Ngôn ngữ tài liệu','English':'Tiếng Anh','Vietnamese':'Tiếng Việt',
 'Program text needs review; copied rates require current-date review.':'Kiểm tra nội dung chương trình; giá sao chép cần rà soát theo ngày đi mới.',
 'Cost saved':'Đã lưu chi phí','Schedule saved':'Đã lưu lịch trình','Quote info saved':'Đã lưu thông tin báo giá','Pricing recalculated':'Đã tính lại giá',
 'Total Cost':'Tổng chi phí','Cost / Paying Pax':'Chi phí / khách trả tiền','Rate Health':'Tình trạng giá','Recalculate & Save':'Tính lại và lưu','Price Health':'Kiểm tra giá','Profit':'Lợi nhuận','Margin':'Biên lợi nhuận',
 'VALIDATION':'Dữ liệu chưa hợp lệ','CONFLICT':'Thao tác không phù hợp trạng thái hiện tại','NOT_FOUND':'Không tìm thấy dữ liệu','FORBIDDEN':'Không có quyền thực hiện','UNAUTHENTICATED':'Vui lòng đăng nhập','SERVER_ERROR':'Không thể xử lý yêu cầu',
 'Issued version is immutable':'Phiên bản đã phát hành không thể sửa','Manual cost requires a review reason in Notes':'Giá nhập tay cần lý do rà soát trong Ghi chú','Review reason required':'Cần nhập lý do rà soát','Review copied costs and refresh rates before approval':'Rà soát chi phí sao chép và cập nhật giá trước khi duyệt','Approved final rate not available for this context':'Không có giá đã duyệt phù hợp ngày đi và điều kiện này','No text extracted; paste or enter the itinerary manually':'Không trích xuất được văn bản; vui lòng dán hoặc nhập lịch trình','No Day / Ngày headings found; use manual entry':'Không tìm thấy tiêu đề Day / Ngày; vui lòng nhập tay','Positive pax and quantity required':'Số khách và số lượng phải lớn hơn 0','Valid travel date required':'Cần ngày đi hợp lệ',
 'Convert to manual with a review reason before changing an approved rate':'Chuyển sang giá nhập tay và ghi lý do trước khi đổi giá đã duyệt',
 'Review copied option lines before approval':'Rà soát các dòng chi phí option sao chép trước khi duyệt',
 'Explain costing pax override':'Ghi lý do số khách tính phí khác tổng khách',
 'Use Smart Quote options for transport capacity and supplement costing':'Dùng option 3*/4*/5* để tính sức chứa xe và phụ phí',
 'Maximum 90 itinerary days':'Tối đa 90 ngày lịch trình','File must be under 10 MB':'Tệp phải nhỏ hơn 10 MB','Use DOCX, PDF or TXT':'Dùng tệp DOCX, PDF hoặc TXT','Upload failed':'Tải tệp thất bại'
 };
 let lang='en';try{lang=localStorage.getItem('vta.language')==='vi'?'vi':'en'}catch{}
 const records=new WeakMap();
 const t=s=>lang==='vi'?(vi[s]||s):s;
 function translate(root=document.body){
  if(!root)return;
  root.querySelectorAll('button,button span,label,th,nav a,.badge,.quote-steps button,h1,h2,option,[data-i18n]').forEach(el=>{
   if(el.closest('[data-no-translate]'))return;
   if(el.tagName==='OPTION'&&!el.hasAttribute('value'))el.setAttribute('value',el.textContent);
   for(const node of el.childNodes){if(node.nodeType!==3)continue;
    let rec=records.get(node);if(!rec||node.nodeValue!==rec.last)rec={base:node.nodeValue,last:node.nodeValue};
    if(el.hasAttribute('data-i18n'))rec.base=el.getAttribute('data-i18n');
    const key=rec.base.trim();const next=vi[key]?rec.base.replace(key,t(key)):rec.base;
    if(node.nodeValue!==next)node.nodeValue=next;rec.last=next;records.set(node,rec);
   }
  });
  document.documentElement.lang=lang;
 }
 window.VTA_I18N={t,get language(){return lang},error:data=>t(data.message||data.error||'SERVER_ERROR'),translate};
 function init(){
  const picker=document.createElement('select');picker.id='vtaLanguage';picker.setAttribute('aria-label','Language / Ngôn ngữ');picker.setAttribute('data-no-translate','');picker.innerHTML='<option value="en">EN</option><option value="vi">VI</option>';picker.value=lang;
  picker.style.cssText='position:fixed;right:18px;bottom:18px;z-index:10000;width:76px;background:white;color:#17362f;border:1px solid #bbb;border-radius:8px;padding:8px';
  document.body.append(picker);picker.onchange=()=>{lang=picker.value;try{localStorage.setItem('vta.language',lang)}catch{}translate();};
  let queued=false;new MutationObserver(()=>{if(!queued){queued=true;queueMicrotask(()=>{queued=false;translate()})}}).observe(document.body,{childList:true,subtree:true,characterData:true});translate();
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();

