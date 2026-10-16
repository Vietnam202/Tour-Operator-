/* VTA RC6.2 image editor: crop creates a new image, never overwrites original asset. */
(()=>{'use strict';if(window.VTAImageCropper)return;
 const MAX_BYTES=12582912,MAX_PIXELS=16000000;
 const CSS=[
 '.vta-crop-dialog{border:0;border-radius:16px;box-shadow:0 25px 85px #0519287a;padding:0;width:min(660px,calc(100vw - 22px));max-height:calc(100vh - 24px);color:#183b51;background:white}',
 '.vta-crop-dialog::backdrop{background:#041c2c99}.vta-crop-dialog .ic-body{padding:24px;display:grid;gap:15px}',
 '.vta-crop-dialog h2{font:700 21px system-ui;margin:0}.vta-crop-dialog p{margin:0;font:13px/1.4 system-ui;color:#50697a}',
 '.vta-crop-dialog canvas{display:block;width:100%;max-height:340px;background:#e8eff2;border-radius:10px}',
 '.vta-crop-dialog label{display:grid;gap:5px;font:700 12px system-ui;color:#284858}',
 '.vta-crop-dialog input,.vta-crop-dialog select{padding:8px;border:1px solid #cedae1;border-radius:7px;width:100%;font:14px system-ui;min-width:0}',
 '.vta-crop-dialog .ic-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:13px}',
 '.vta-crop-dialog .ic-actions{display:flex;justify-content:flex-end;gap:9px;align-items:center}',
 '.vta-crop-dialog button{border:1px solid #bccbd4;background:white;border-radius:8px;padding:9px 17px;cursor:pointer;font:700 13px system-ui}',
 '.vta-crop-dialog button.primary{background:#0b777b;color:white;border:0}',
 '@media(max-width:500px){.vta-crop-dialog .ic-body{padding:14px}.vta-crop-dialog .ic-grid{grid-template-columns:1fr}}'
 ].join('');
 function assertFile(file){if(!(file instanceof Blob)||!/^image\/(jpeg|png|webp)$/.test(file.type)||file.size>MAX_BYTES||file.size===0)throw Error('Use JPEG/PNG/WebP under 12 MB.');}
 function calculate(w,h,ratio,zoom=1,focalX=.5,focalY=.5){
   if(!Number.isFinite(w)||!Number.isFinite(h)||w<=0||h<=0||w*h>MAX_PIXELS)throw Error('Image exceeds 16 megapixels');
   if(!Number.isFinite(ratio)||ratio<=0||ratio>5)throw Error('Invalid crop ratio');
   const cw=Math.min(1600,w,Math.max(200,Math.round(1200*ratio))),ch=Math.round(cw/ratio),base=Math.max(cw/w,ch/h)*Math.max(1,Math.min(3,zoom)),dw=w*base,dh=h*base;
   return {width:cw,height:ch,x:(cw-dw)*Math.max(0,Math.min(1,focalX)),y:(ch-dh)*Math.max(0,Math.min(1,focalY)),drawWidth:dw,drawHeight:dh};
 }
 function draw(canvas,img,ratio,zoom,fx,fy){
   const c=calculate(img.naturalWidth,img.naturalHeight,ratio,zoom,fx,fy);
   canvas.width=c.width;canvas.height=c.height;
   const ctx=canvas.getContext('2d');if(!ctx)throw Error('Canvas unavailable');
   ctx.fillStyle='#fff';ctx.fillRect(0,0,c.width,c.height);ctx.imageSmoothingQuality='high';ctx.drawImage(img,c.x,c.y,c.drawWidth,c.drawHeight);
 }
 async function open({file,alt=''}){
   assertFile(file);const url=URL.createObjectURL(file),img=new Image();let dialog;
   try{
     img.src=url;await img.decode();
     if(img.naturalWidth*img.naturalHeight>MAX_PIXELS)throw Error('Image exceeds 16 megapixels');
     if(typeof HTMLDialogElement==='undefined')throw Error('Browser crop dialog unavailable');
     if(!document.getElementById('vta-crop-css')){const css=document.createElement('style');css.id='vta-crop-css';css.textContent=CSS;document.head.append(css);}
     dialog=document.createElement('dialog');dialog.className='vta-crop-dialog';dialog.setAttribute('aria-label','Edit tour image');
     dialog.innerHTML='<div class="ic-body"><h2>Edit tour image</h2><p>Crop a new image copy. The original is preserved until you apply and save.</p><canvas aria-label="Image crop preview"></canvas><div class="ic-grid">'
       +'<label>Crop ratio<select data-ic="ratio"><option value="original">Original</option><option value="1">Square 1:1</option><option value="1.33333333">4:3</option><option value="1.77777778">16:9 Cover</option><option value=".75">3:4 Portrait</option></select></label>'
       +'<label>Zoom<input data-ic="zoom" type="range" min="100" max="300" step="5" value="100"></label>'
       +'<label>Horizontal focus<input data-ic="fx" type="range" min="0" max="100" value="50"></label>'
       +'<label>Vertical focus<input data-ic="fy" type="range" min="0" max="100" value="50"></label>'
       +'</div><label>Image description<input data-ic="alt" maxlength="300" placeholder="e.g. Golden Bridge, Da Nang"></label>'
       +'<div class="ic-actions"><button type="button" data-ic="cancel">Cancel</button><button type="button" class="primary" data-ic="apply">Apply new image</button></div></div>';
     document.body.append(dialog);
     const get=k=>dialog.querySelector('[data-ic="'+k+'"]'),canvas=dialog.querySelector('canvas');
     get('alt').value=String(alt||'').slice(0,300);
     const redraw=()=>draw(canvas,img,get('ratio').value==='original'?img.naturalWidth/img.naturalHeight:Number(get('ratio').value),Number(get('zoom').value)/100,Number(get('fx').value)/100,Number(get('fy').value)/100);
     ['ratio','zoom','fx','fy'].forEach(k=>get(k).addEventListener('input',redraw));redraw();
     return await new Promise((resolve,reject)=>{
       let finished=false;const done=v=>{if(finished)return;finished=true;resolve(v);dialog.close();};
       get('cancel').onclick=()=>done(null);dialog.addEventListener('cancel',e=>{e.preventDefault();done(null);});
       get('apply').onclick=()=>{try{redraw();get('apply').disabled=true;canvas.toBlob(blob=>{
         if(!blob){reject(Error('Image processing failed'));dialog.close();return;}
         const name=((file.name||'tour-image').replace(/\.[^.]+$/,'').slice(0,80)||'tour-image')+'-edited.jpg';
         done({file:new File([blob],name,{type:'image/jpeg'}),alt:get('alt').value.trim(),width:canvas.width,height:canvas.height});
       },'image/jpeg',.88);}catch(e){reject(e);dialog.close();}};
       dialog.showModal();
     });
   }finally{if(dialog)dialog.remove();URL.revokeObjectURL(url);}
 }
 window.VTAImageCropper=Object.freeze({open,calculate,assertFile});
})();