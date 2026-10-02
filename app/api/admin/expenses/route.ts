import { NextResponse } from "next/server";
import { ExpenseCategory } from "@prisma/client";
import { prisma } from "@/lib/prisma";
import { requireAdminPermission } from "@/lib/admin-auth";
import { sameOrigin } from "@/lib/csrf";

export async function POST(request:Request){
 if(!sameOrigin(request)) return NextResponse.json({error:"Invalid request origin"},{status:403});
 if(!(await requireAdminPermission(request,"payments:write"))) return NextResponse.json({error:"Unauthorized"},{status:401});
 const body=await request.json();
 const amount=Math.round(Number(body.amount));
 const category=String(body.category||"OTHER") as ExpenseCategory;
 if(!body.bookingId||!Number.isFinite(amount)||amount<=0||!Object.values(ExpenseCategory).includes(category)) return NextResponse.json({error:"Valid booking, category and positive amount are required"},{status:400});
 const expense=await prisma.bookingExpense.create({data:{bookingId:String(body.bookingId),category,amount,currency:String(body.currency||"USD").slice(0,3).toUpperCase(),description:String(body.description||"").trim().slice(0,500)||null,paidAt:body.paidAt?new Date(body.paidAt):null}});
 return NextResponse.json({expense},{status:201});
}
