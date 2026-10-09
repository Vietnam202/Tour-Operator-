import { Editor, Node, Extension, mergeAttributes } from '@tiptap/core';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table';
import TextAlign from '@tiptap/extension-text-align';
import { TextStyleKit } from '@tiptap/extension-text-style';

const AssetImage = Image.extend({
  addAttributes() {
    return { ...this.parent?.(), assetId: {default:null, parseHTML:el=>Number(el.getAttribute('data-asset-id'))||null, renderHTML:a=>a.assetId?{'data-asset-id':a.assetId}:{} } };
  },
  renderHTML({HTMLAttributes}) {
    const a={...HTMLAttributes}; delete a.src;
    if (a['data-asset-id']) a.src='api/index.php?route='+encodeURIComponent('media/'+a['data-asset-id']+'/image');
    return ['img', mergeAttributes(this.options.HTMLAttributes,a)];
  }
}).configure({resize:{enabled:true,directions:['bottom-right'],alwaysPreserveAspectRatio:true},allowBase64:false});
const PageBreak = Node.create({
  name:'pageBreak',group:'block',atom:true,selectable:true,
  parseHTML:()=>[{tag:'div[data-page-break]'}],
  renderHTML:()=>['div',{'data-page-break':'true',class:'wd-page-break',contenteditable:'false'},'Page break'],
});
const PublicSection = Extension.create({
  name:'vtaPublicSection',
  addGlobalAttributes(){return [{types:['heading','paragraph','table'],attributes:{vtaSection:{default:null,parseHTML:el=>el.getAttribute('data-vta-section')==='pricing'?'pricing':null,renderHTML:a=>a.vtaSection==='pricing'?{'data-vta-section':'pricing'}:{}}}}];}
});
window.VtaDocumentEngine = {
  version:'Tiptap 3.31.3 / ProseMirror',
  create(element,options) {
    return new Editor({element,extensions:[
      StarterKit.configure({link:false,code:false,codeBlock:false,strike:false}),
      TextStyleKit.configure({backgroundColor:false,fontFamily:false,lineHeight:false}),
      TextAlign.configure({types:['heading','paragraph']}),
      TableKit.configure({table:{resizable:true}}),AssetImage,PageBreak,PublicSection
    ],...options});
  }
};
