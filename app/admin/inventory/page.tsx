"use client";

import { FormEvent, useEffect, useState } from "react";
import { useRouter } from "next/navigation";

type Cabin={id:string;name:string;basePrice:number;netCost?:number|null;capacity:number;childRatePct:number;singleSupplement:number;isActive:boolean};
type Departure={id:string;departureDate:string;durationNights:number;priceFrom:number;netCostFrom?:number|null;holidaySurcharge:number;cabinsLeft:number|null;isAvailable:boolean};
type Cruise={id:string;name:string;slug:string;route:string;status:string;stars:number;badge?:string;supplierId?:string|null;cabins:Cabin[];departures:Departure[]};

export default function InventoryAdmin(){
 const router=useRouter();
 const [staff,setStaff]=useState<{name:string;role:string}|null>(null);
 const [cruises,setCruises]=useState<Cruise[]>([]);
 const [selected,setSelected]=useState("");
 const [error,setError]=useState("");
 const [message,setMessage]=useState("");
 const [loading,setLoading]=useState(false);
 const [suppliers,setSuppliers]=useState<{id:string;name:string}[]>([]);

 async function request(url:string,options:RequestInit={}){
   const res=await fetch(url,{...options,headers:{"content-type":"application/json",...(options.headers||{})}});
   const data=await res.json();
   if(!res.ok) throw new Error(data.error||"Request failed");
   return data;
 }
 async function load(e?:FormEvent){
   e?.preventDefault(); setLoading(true);setError("");setMessage("");
   try{const data=await request("/api/admin/cruises");setCruises(data.cruises||[]);request("/api/admin/suppliers").then(d=>setSuppliers(d.suppliers||[])).catch(()=>{});if(!selected&&data.cruises?.[0])setSelected(data.cruises[0].id)}
   catch(err){setError(err instanceof Error?err.message:"Unable to load inventory")}
   finally{setLoading(false)}
 }
 async function createSupplier(e:FormEvent<HTMLFormElement>){
   e.preventDefault();setError("");const f=new FormData(e.currentTarget);
   try{await request("/api/admin/suppliers",{method:"POST",body:JSON.stringify(Object.fromEntries(f))});e.currentTarget.reset();setMessage("Supplier created.");await load()}
   catch(err){setError(err instanceof Error?err.message:"Unable to create supplier")}
 }
 async function createCruise(e:FormEvent<HTMLFormElement>){
   e.preventDefault(); setError(""); const f=new FormData(e.currentTarget);
   try{await request("/api/admin/cruises",{method:"POST",body:JSON.stringify(Object.fromEntries(f))});e.currentTarget.reset();setMessage("Cruise created.");await load()}
   catch(err){setError(err instanceof Error?err.message:"Unable to create cruise")}
 }
 async function createCabin(e:FormEvent<HTMLFormElement>){
   e.preventDefault(); if(!selected)return; setError(""); const f=new FormData(e.currentTarget);
   try{await request("/api/admin/cabins",{method:"POST",body:JSON.stringify({...Object.fromEntries(f),cruiseId:selected})});e.currentTarget.reset();setMessage("Cabin added.");await load()}
   catch(err){setError(err instanceof Error?err.message:"Unable to add cabin")}
 }
 async function createDeparture(e:FormEvent<HTMLFormElement>){
   e.preventDefault(); if(!selected)return; setError(""); const f=new FormData(e.currentTarget);
   try{await request("/api/admin/departures",{method:"POST",body:JSON.stringify({...Object.fromEntries(f),cruiseId:selected})});e.currentTarget.reset();setMessage("Departure and rate added.");await load()}
   catch(err){setError(err instanceof Error?err.message:"Unable to add departure")}
 }
 async function setStatus(cruise:Cruise,status:string){
   try{await request("/api/admin/cruises/"+cruise.id,{method:"PATCH",body:JSON.stringify({status})});setMessage("Cruise status updated.");await load()}
   catch(err){setError(err instanceof Error?err.message:"Unable to update cruise")}
 }

 useEffect(()=>{fetch("/api/admin/auth/me").then(async r=>{if(!r.ok){router.replace("/admin/login");return null}return r.json()}).then(d=>{if(d?.user){setStaff(d.user);load()}}).catch(()=>router.replace("/admin/login"))},[router]);
 async function logout(){await fetch("/api/admin/auth/logout",{method:"POST"});router.replace("/admin/login");router.refresh()}
 const canWrite=staff?.role==="ADMIN"||staff?.role==="OPERATIONS";
 const current=cruises.find(c=>c.id===selected);
 return <main className="adminPage">
  <header className="adminHeader"><div className="shell"><a className="brand" href="/"><span className="brandMark">≋</span><span><b>HALONG CRUISE<br/>ADVISOR</b><small>Inventory</small></span></a><nav className="adminNav"><a href="/admin">Bookings</a><a href="/admin/operations">Today</a><a className="active" href="/admin/inventory">Inventory</a><a href="/">Website</a></nav></div></header>
  <div className="shell adminWrap">
   <div className="adminTitle"><span className="eyebrow">PRODUCT OPERATIONS</span><h1>Cruises, cabins & departures</h1><p>Maintain bookable cruise products and USD starting rates without editing source code.</p></div>
   <div className="staffBar"><span>{staff?staff.name+" · "+staff.role:"Checking session..."}</span><div><button onClick={()=>load()} disabled={loading}>{loading?"Refreshing...":"Refresh"}</button><button onClick={logout}>Sign out</button></div></div>
   {error&&<div className="adminError">{error}</div>}{message&&<div className="adminSuccess">{message}</div>}

   <div className="inventoryGrid">
    <section className="inventoryPanel">
     <div className="panelTitle"><h2>Cruise operators</h2><span>Supplier contracts and operations contacts.</span></div>
     {canWrite?<form className="inventoryForm" onSubmit={createSupplier}>
      <label><span>Supplier name</span><input name="name" required placeholder="e.g. Stellar Cruise JSC"/></label>
      <label><span>Legal name</span><input name="legalName" placeholder="Contract entity"/></label>
      <label><span>Contact person</span><input name="contactName" placeholder="Operations / Sales contact"/></label>
      <div className="miniGrid"><input name="email" type="email" placeholder="supplier@example.com"/><input name="phone" placeholder="+84..."/></div>
      <label><span>Contract notes</span><textarea name="notes" placeholder="Payment terms, blackout dates, cancellation terms..."/></label>
      <button className="darkButton">Add supplier</button>
     </form>:<div className="inventoryEmpty">Your role has read-only supplier access.</div>}
     <div className="inventoryItems">{suppliers.map(s=><div className="inventoryItem" key={s.id}><b>{s.name}</b></div>)}{!suppliers.length&&<p className="muted">No suppliers yet.</p>}</div>
    </section>
    <section className="inventoryPanel">
     <div className="panelTitle"><h2>Create cruise</h2><span>Start as DRAFT until content is ready.</span></div>
     {canWrite?<form className="inventoryForm" onSubmit={createCruise}>
      <label><span>Cruise name</span><input name="name" required placeholder="e.g. Elite of the Seas"/></label>
      <label><span>URL slug</span><input name="slug" required placeholder="elite-of-the-seas"/></label>
      <label><span>Route</span><input name="route" required placeholder="Halong Bay - Lan Ha Bay"/></label>
      <div className="miniGrid"><label><span>Stars</span><select name="stars" defaultValue="5"><option>5</option><option>4</option></select></label><label><span>Status</span><select name="status" defaultValue="DRAFT"><option>DRAFT</option><option>PUBLISHED</option></select></label></div>
      <label><span>Badge</span><input name="badge" placeholder="Best Seller"/></label>
      <label><span>Hero image URL</span><input name="heroImage" placeholder="https://..."/></label>
      <label><span>Summary</span><textarea name="summary" placeholder="Short international-facing description"/></label>
      <button className="darkButton">Create cruise</button>
     </form>:<div className="inventoryEmpty">Your role has read-only inventory access.</div>}
    </section>

    <section className="inventoryPanel inventoryMain">
     <div className="panelTitle"><h2>Manage inventory</h2><span>{cruises.length} cruise{cruises.length===1?"":"s"} loaded</span></div>
     <label className="cruiseSelect"><span>Select cruise</span><select value={selected} onChange={e=>setSelected(e.target.value)}>{cruises.map(c=><option key={c.id} value={c.id}>{c.name} - {c.status}</option>)}</select></label>
     {!current?<div className="inventoryEmpty">Load inventory or create your first cruise.</div>:<>
       <div className="inventoryCruiseHead"><div><h3>{current.name}</h3><p>{current.route} · {current.stars}-star</p></div><select value={current.supplierId||""} disabled={!canWrite} onChange={e=>request("/api/admin/cruises/"+current.id,{method:"PATCH",body:JSON.stringify({supplierId:e.target.value||null})}).then(()=>load())}><option value="">No supplier</option>{suppliers.map(s=><option key={s.id} value={s.id}>{s.name}</option>)}</select><select value={current.status} disabled={!canWrite} onChange={e=>setStatus(current,e.target.value)}><option>DRAFT</option><option>PUBLISHED</option><option>ARCHIVED</option></select></div>
       <div className="inventoryColumns">
        <div>
         <h3>Cabins</h3>
         <div className="inventoryItems">{current.cabins.map(c=><div className="inventoryItem" key={c.id}><div><b>{c.name}</b><span>Capacity {c.capacity} · Child {c.childRatePct}% · Single +US$ {c.singleSupplement}</span></div><strong>US$ {c.basePrice}<small>Cost {c.netCost==null?"-":"US$ "+c.netCost}</small></strong></div>)}{!current.cabins.length&&<p className="muted">No cabins yet.</p>}</div>
         {canWrite&&<form className="compactForm" onSubmit={createCabin}><input name="name" required placeholder="Cabin name"/><div className="miniGrid"><input name="basePrice" type="number" required placeholder="Sell USD"/><input name="netCost" type="number" min="0" placeholder="Net cost USD"/></div><div className="miniGrid"><input name="capacity" type="number" defaultValue="2" placeholder="Capacity"/></div><div className="miniGrid"><input name="childRatePct" type="number" min="0" max="100" defaultValue="70" placeholder="Child rate %"/><input name="singleSupplement" type="number" min="0" defaultValue="0" placeholder="Single +USD"/></div><input name="sizeSqm" type="number" placeholder="Size m²"/><input name="description" placeholder="Balcony, deck, view..."/><button className="darkButton">Add cabin</button></form>}
        </div>
        <div>
         <h3>Departures & rates</h3>
         <div className="inventoryItems">{current.departures.map(d=><div className="inventoryItem" key={d.id}><div><b>{new Date(d.departureDate).toLocaleDateString("en-US",{month:"short",day:"numeric",year:"numeric"})}</b><span>{d.durationNights+1}D{d.durationNights}N · {d.cabinsLeft??"?"} cabins left · Holiday +US$ {d.holidaySurcharge}</span></div><strong>US$ {d.priceFrom}<small>Cost {d.netCostFrom==null?"-":"US$ "+d.netCostFrom}</small></strong></div>)}{!current.departures.length&&<p className="muted">No departures yet.</p>}</div>
         {canWrite&&<form className="compactForm" onSubmit={createDeparture}><input name="departureDate" type="date" required/><div className="miniGrid"><select name="durationNights" defaultValue="1"><option value="1">2D1N</option><option value="2">3D2N</option></select><input name="priceFrom" type="number" required placeholder="Sell USD"/></div><div className="miniGrid"><input name="netCostFrom" type="number" min="0" placeholder="Net cost USD"/><input name="cabinsLeft" type="number" min="0" placeholder="Cabins left"/><input name="holidaySurcharge" type="number" min="0" defaultValue="0" placeholder="Holiday +USD"/></div><button className="darkButton">Add departure</button></form>}
        </div>
       </div>
     </>}
    </section>
   </div>
  </div>
 </main>
}
