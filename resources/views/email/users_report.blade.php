Current users:<br/>
<table>
    <tr>
        <th>Id</th>
        <th>Name</th>
        <th>Email</th>
        <th>Registered on</th>
        <th>Drones</th>
    </tr>
    @foreach($currentUsers as $user)
        <tr>
            <td>{{$user->id}}</td>
            <td>
                {{$user->name}}
            </td>
            <td>
                {{$user->email}}
            </td>
            <td>
                {{date('m-d-Y', strtotime($user->created_at))}}
            </td>
            <td>
                {{count($user->agents)}}
            </td>
        </tr>
    @endforeach
</table>