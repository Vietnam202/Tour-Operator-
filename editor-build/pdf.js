import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist/legacy/build/pdf.mjs';
GlobalWorkerOptions.workerSrc='assets/document-pdf-worker.js?v=RC62-STUDIO-20261009';
window.VtaDocumentPDF={async extract(file){
 const task=getDocument({data:new Uint8Array(await file.arrayBuffer()),isEvalSupported:false,enableXfa:false,useSystemFonts:true,stopAtErrors:true});
 try{
  const pdf=await task.promise;if(pdf.numPages>200)throw Error('Import PDFs with up to 200 pages.');const lines=[];let characters=0;
  for(let i=1;i<=pdf.numPages;i++){
   const page=await pdf.getPage(i),content=await page.getTextContent();let line='',y=null;
   for(const item of content.items){if(typeof item.str!=='string')continue;const at=item.transform?.[5];if(y!==null&&Number.isFinite(at)&&Math.abs(at-y)>2&&line){lines.push(line);line='';}line+=(line&&!/\s$/.test(line)?' ':'')+item.str;y=at;if(item.hasEOL){lines.push(line);line='';}characters+=item.str.length;if(characters>250000)throw Error('PDF text exceeds 250 KB; split the file before importing.');}
   if(line)lines.push(line);if(i<pdf.numPages)lines.push('\f');page.cleanup();
  }
  const text=lines.filter(s=>s!=='\f').join('\n');if(!text.trim())throw Error('PDF contains no searchable text. OCR is not available.');
  return {text,lines,note:'PDF searchable text converted to editable paragraphs.',warnings:['PDF columns, tables, images and formatting are not reconstructed. Review reading order. OCR is not available.']};
 }finally{await task.destroy();}
}};